<?php

namespace BitsnBolts\Flysystem\Sharepoint;

use Exception;
use League\Flysystem\Util;
use League\Flysystem\Config;
use Office365\SharePoint\File;
use Office365\SharePoint\Folder;
use Office365\SharePoint\ListItem;
use Office365\Runtime\Http\HttpMethod;
use Office365\SharePoint\ClientContext;
use Office365\Runtime\Http\RequestOptions;
use Office365\SharePoint\ListTemplateType;
use League\Flysystem\FileNotFoundException;
use Office365\Runtime\Auth\UserCredentials;
use League\Flysystem\Adapter\AbstractAdapter;
use Office365\SharePoint\FileCreationInformation;
use Office365\SharePoint\ListCreationInformation;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use Office365\Runtime\Http\RequestException;
use RuntimeException;

class SharepointAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var ClientContext
     */
    protected $client;

    /**
     * @var UserCredentials
     */
    protected $auth;

    /**
     * @var array
     */
    protected $settings;

    protected $fileCache = [];
    protected $listCache = [];
    protected $folderCache = [];

    /**
     * @var string[]
     */
    protected static $metaOptions
        = [
            'CacheControl',
            'ContentType',
            'Metadata',
            'ContentLanguage',
            'ContentEncoding',
        ];

    /**
     * Constructor.
     *
     * @param ClientContext $sharepointClient
     * @param string        $prefix
     */
    public function __construct($settings, $prefix = null)
    {
        $this->settings = $settings;
        $this->authorize();
        $this->setupClient();
        $this->setPathPrefix($prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, stream_get_contents($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, stream_get_contents($resource), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $file = $this->getFileByPath($path);
        $file->moveTo($newpath, 1);
        $this->client->executeQuery();
        return true;
    }

    /**
     * Retrieve url for provided file path. This helps support Laravel Flysystem support
     * This will return the ServerRelativeUrl property
     *
     * @see https://github.com/illuminate/filesystem/blob/master/FilesystemAdapter.php
     *
     * @param string $path
     * The path of the file
     *
     * @return string The server relative url for this file
     * @throws FileNotFoundException|ListNotFoundException
     */
    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);
        $file = $this->getFileByPath($path);
        if (!$file) {
            throw new FileNotFoundException($path);
        }
        if ($file->getLinkingUri()) {
            return $file->getLinkingUri();
        }

        $base = $file->getContext()->getBaseUrl();
        $relativeUrl = substr($file->getServerRelativeUrl(), strlen(parse_url($base)['path']));
        return $base . $relativeUrl;
    }

    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        // @todo.
//        $file = $this->getFileByPath($path);
//        $file->moveTo($newpath);
//        $this->client->executeQuery();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        if ($this->isFolder($path)) {
            $folder = $this->getFolderForPath($path, $this->getList($path), false, true);
            $folder->recycle();
        } else {
            try {
                $file = $this->getFileByPath($path, true);
                $file->recycle();
            } catch (FileNotFoundException $e) {
                return true;
            }
        }

        $this->client->executeQuery();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $dirname = $this->applyPathPrefix($dirname);

        // @todo: implement the deleteDir action.
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        if (dirname($dirname) === '.') {
            $this->createList($dirname);
        } else {
            $directories = explode('/', $dirname);
            $list = $this->getList(array_shift($directories));
            $this->createFolderInList($list, implode('/', $directories));
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        if ($this->isFolder($path)) {
            try {
                $this->getFolderForPath($path, $this->getList($path), false, true);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        try {
            $this->getFileByPath($path, true);
        } catch (FileNotFoundException $e) {
            return false;
        } catch (ListNotFoundException $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $path = $this->applyPathPrefix($path);
        $file = $this->getFileByPath($path);
        $fileContent = File::openBinary(
            $this->client,
            $file->getProperty('ServerRelativeUrl')
        );
        $response = array('contents' => $fileContent);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $path = $this->applyPathPrefix($path);

        // @todo
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);
        $listing = $this->showList($directory);

        if (count($listing) === 0) {
            return [];
        }

        $normalizer = [$this, 'normalizeFileResponse'];
        $paths = array_fill(0, count($listing), $directory);
        $normalized = array_map($normalizer, $listing, $paths);

        $folders = $this->showListFolders($directory);
        $folderNormalizer = [$this, 'normalizeFolderResponse'];
        $paths = array_fill(0, count($folders), $directory);
        $normalizedFolder = array_map($folderNormalizer, $folders, $paths);

        $result = array_merge($normalized, $normalizedFolder);

        return Util::emulateDirectories($result);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        $file = $this->getFileByPath($path);

        return $this->normalizeFileResponse($file, dirname($path));
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        // TODO: Implement getVisibility() method.
    }

    public function grantUserAccessToPath($loginName, $path)
    {
        // @todo: only do this when user doesnt have the permissions yet?
        $this->breakRoleInheritance($path);
        $url = $this->buildAccessUrl($loginName, $path);
        $request = new RequestOptions($url, null, null, HttpMethod::Post);
        $this->client->ensureFormDigest($request);
        $this->client->executeQueryDirect($request);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Normalize the object result array.
     *
     * @param array  $response
     * @param string $path
     *
     * @return array
     */
    protected function normalizeFileResponse(File $item, $path = null)
    {
        $path = $this->removePathPrefix($path);

        $modified = date_create($item->getTimeLastModified())->format('U');
        $created = date_create($item->getTimeCreated())->format('U');

        return [
            'path'       => $path . '/' . $item->getName(),
            'linkingUrl' => $item->getLinkingUrl(),
            'timestamp'  => (int) $modified,
            'created'  => (int) $created,
            'dirname'    => $path,
            'mimetype'   => '',
            'size'       => $item->getLength(),
            'type'       => 'file',
        ];
    }

    /**
     * Normalize the object result array.
     *
     * @param array  $response
     * @param string $path
     *
     * @return array
     */
    protected function normalizeFolderResponse(Folder $item, $path = null)
    {
        if (in_array($item->getName(), ['Forms'])) {
            return ['type' => 'other', 'path' => $path];
        }

        $path = $this->removePathPrefix($path);

        $modified = date_create($item->getTimeLastModified())->format('U');
        $created = date_create($item->getTimeCreated())->format('U');

        return [
            'path'       => $path . '/' . $item->getName(),
            'timestamp'  => (int) $modified,
            'created'  => (int) $created,
            'dirname'    => $path,
            'mimetype'   => '',
            'size'       => 0,
            'type'       => 'dir',
        ];
    }

    /**
     * Builds the normalized output array.
     *
     * @param string $path
     * @param int    $timestamp
     * @param mixed  $content
     *
     * @return array
     */
    protected function normalize($path, $timestamp, $content = null)
    {
        $data = [
            'path'      => $path,
            'timestamp' => (int)$timestamp,
            'dirname'   => Util::dirname($path),
            'type'      => 'file',
        ];

        if (is_string($content)) {
            $data['contents'] = $content;
        }

        return $data;
    }

    /**
     * Builds the normalized output array from a Blob object.
     *
     * @param string         $path
     * @param BlobProperties $properties
     *
     * @return array
     */
    protected function normalizeBlobProperties(
        $path,
        BlobProperties $properties
    ) {
        if (substr($path, -1) === '/') {
            return [
                'type' => 'dir',
                'path' => $this->removePathPrefix(rtrim($path, '/'))
            ];
        }

        $path = $this->removePathPrefix($path);

        return [
            'path'      => $path,
            'timestamp' => (int)$properties->getLastModified()->format('U'),
            'dirname'   => Util::dirname($path),
            'mimetype'  => $properties->getContentType(),
            'size'      => $properties->getContentLength(),
            'type'      => 'file',
        ];
    }

    /**
     * Builds the normalized output array from a BlobPrefix object.
     *
     * @param BlobPrefix $blobPrefix
     *
     * @return array
     */
    protected function normalizeBlobPrefix(BlobPrefix $blobPrefix)
    {
        return [
            'type' => 'dir',
            'path' => $this->removePathPrefix(rtrim(
                $blobPrefix->getName(),
                '/'
            ))
        ];
    }

    /**
     * Retrieves content streamed by Sharepoint into a string.
     *
     * @param resource $resource
     *
     * @return string
     */
    protected function streamContentsToString($resource)
    {
        return stream_get_contents($resource);
    }

    /**
     * Upload a file.
     *
     * @param string          $path     Path
     * @param string|resource $contents Either a string or a stream.
     * @param Config          $config   Config
     *
     * @return array
     */
    protected function upload($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);
        $result = $this->addFileToList($path, $contents);
        $modified = date_create($result->getTimeLastModified())->format('U');

        return $this->normalize(
            $result->getServerRelativeUrl(),
            $modified,
            $contents
        );
    }

    /**
     * Retrieve options from a Config instance.
     *
     * @param Config $config
     */
    protected function getOptionsFromConfig(Config $config)
    {
        // @todo
    }

    protected function authorize()
    {
        $this->auth = new UserCredentials($this->settings['username'], $this->settings['password']);
    }

    private function getContributorRole()
    {
        $roleDefinitions = $this->client->getWeb()->getRoleDefinitions();
        $roleDefinitions->filter('RoleTypeKind eq 3');
        $this->client->load($roleDefinitions);
        $this->client->executeQuery();

        return $roleDefinitions->getItem(0);
    }

    private function showList($listTitle)
    {
        $items = $this->client->getWeb();

        // get the right list.
        $folders = explode('/', $listTitle);
        while($folderName = array_shift($folders)) {
            $items = $items->getFolders()
                ->getByUrl($folderName);
        }

        $items = $items
            ->getFiles()
            ->get()
            ->executeQuery();
        return $items->getData();
    }

    private function showListFolders($listTitle)
    {
        $items = $this->client
            ->getWeb()
            ->getFolders()
            ->getByUrl($listTitle)
            ->getFolders()
            ->get()
            ->executeQuery();
        return $items->getData();
    }

    private function getList($path)
    {
        // @todo: create a dedicated Path Object.
        $listTitle = $this->getListTitleForPath($path);
        if (array_key_exists($listTitle, $this->listCache)) {
            return $this->listCache[$listTitle];
        }
        $lists = $this->client->getWeb()->getLists()->filter('Title eq \''
                                                             . $listTitle
                                                             . '\'')
                              ->top(1);
        $this->client->load($lists);
        $this->client->executeQuery();
        if ($lists->getCount() === 0) {
            throw new ListNotFoundException();
        }

        $list = $lists->getItem(0);
        $this->listCache[$listTitle] = $list;
        return $list;
    }

    private function getListTitleForPath($path)
    {
        return current(explode('/', $path));
    }

    private function getFolderTitleForPath($path)
    {
        $parts = explode('/', $path);

        // If the last part cotains a dot, its a file! :)
        // We dont need files here, so pop it.
        if (!$this->isFolder(end($parts))) {
            array_pop($parts);
        }

        $list = array_shift($parts);
        return implode('/', $parts);
    }

    private function isFolder($path)
    {
        return strpos($path, '.') === false;
    }

    // @todo: I dont like that I have two types of paths in the adapter..
    // @todo: pick one?
    private function getListTitleForGroupPath($path)
    {
        $parts = explode('/', $path);

        return $parts[0];
    }

    private function getFilenameForPath($path)
    {
        return str_replace('\'', '\'\'', basename($path));
    }

    /**
     * @param $path
     *
     * @return mixed
     * @throws ListNotFoundException|FileNotFoundException
     */
    private function getFileByPath($path, $fresh = false)
    {
        if (!$fresh && array_key_exists($path, $this->fileCache)) {
            return $this->fileCache[$path];
        }
        $list = $this->getList($path);
        $folder = $this->getFolderForPath($path, $list);
        $items = $folder->getFiles();
        $filename = $this->getFilenameForPath($path);
        $items->filter('Name eq \'' . $filename . '\'')->top(1);
        $this->client->load($items);
        try {
            $this->client->executeQuery();
        } catch (RequestException $e) {
            throw new FileNotFoundException($path);
        }
        if ($items->getCount() === 0) {
            throw new FileNotFoundException($path);
        }
        $file = $items->getItem(0);
        $this->client->load($file);
        try {
            $this->client->executeQuery();
        } catch (Exception $exception) {
            throw new FileNotFoundException($path);
        }
        $this->fileCache[$path] = $file;
        return $file;
    }

    /**
     * @return \Office365\SharePoint\User
     */
    private function getUserByLoginName($loginName)
    {
        try {
            $user = $this->client->getWeb()->ensureUser($loginName)->executeQuery();
        } catch (Exception $e) {
            die('<b>Foutmelding:</b> De gebruikersnaam ' . $loginName . ' is niet gevonden in Office 365.');
        }

        return $user;
    }

    /**
     * @param $email
     * @param $path
     *
     * @return string
     */
    private function buildAccessUrl($loginName, $path)
    {
        $listTitle = $this->getListTitleForGroupPath($path);
        $user = $this->getUserByLoginName($loginName);
        $role = $this->getContributorRole();
        $url = $this->settings['url']
               . "/_api/web/lists/getbytitle('{$listTitle}')/roleassignments/addroleassignment(principalid={$user->Id},roledefid={$role->Id})";

        return $url; //     $request = new \Office365\Runtime\Utilities\RequestOptions($fullUrl, null, null, HttpMethod::Post);
    }

    private function printLists()
    {
        $lists = $this->client->getWeb()->getLists();
        $this->client->load($lists);
        $this->client->executeQuery();
        foreach ($lists->getData() as $list) {
            print "List title: '{$list->Title}'\r\n";
        }
    }

    private function createList($listTitle)
    {
        $info = new ListCreationInformation($listTitle);
        $info->BaseTemplate = ListTemplateType::DocumentLibrary;
        $list = $this->client->getWeb()->getLists()->add($info);
        $this->client->executeQuery();

        $connector = $list->getContext();
        $list->breakRoleInheritance(true);
        $connector->executeQuery();

        return $list;
    }

    private function breakRoleInheritance($path)
    {
        $list = $this->getList($path);
        $connector = $list->getContext();
        $list->breakRoleInheritance(true);
        $connector->executeQuery();
        return $list;
    }

    private function addFileToList($path, $content)
    {
        try {
            $list = $this->getList($path);
        } catch (ListNotFoundException $e) {
            $list = $this->createList($this->getListTitleForPath($path));
        }
        $folder = $this->getFolderForPath($path, $list);
        $connector = $list->getContext();

        $uploadedFile = $this->uploadFileToList(
            $path,
            $content,
            $folder,
            $connector
        );

        return $uploadedFile;
    }

    /**
     * @param $list
     * @param $folderName
     *
     * @return mixed
     */
    private function createFolderInList($list, $folderName)
    {
        $parentFolder = $list->getRootFolder();
        $childFolder = $parentFolder->getFolders()->add($folderName);
        $this->client->executeQuery();
        return $childFolder;
    }

    /**
     * @param $path
     * @param $list
     *
     * @return \Office365\SharePoint\Folder
     */
    private function getFolderForPath($path, $list, $createIfMissing = true, $fresh = false)
    {
        $folderName = $this->getFolderTitleForPath($path);
        $serverRelativeUrl = $list->getProperty('ParentWebUrl')
                               . '/'
                               . $list->getProperty('Title')
                               . '/'
                               . $folderName;
        if (!$fresh && array_key_exists($serverRelativeUrl, $this->folderCache)) {
            return $this->folderCache[$serverRelativeUrl];
        }

        $folder = $this->client->getWeb()
                               ->getFolderByServerRelativeUrl($serverRelativeUrl);
        $this->client->load($folder);
        try {
            $this->client->executeQuery();
        } catch (Exception $e) {
            if ($createIfMissing) {
                $folder = $this->createFolderInList($list, $folderName);
            } else {
                throw $e;
            }
        }

        $this->folderCache[$serverRelativeUrl] = $folder;
        return $folder;
    }

    /**
     * @param $path
     * @param $content
     * @param $folder
     * @param $connector
     *
     * @return mixed
     */
    private function uploadFileToList($path, $content, $folder, $connector)
    {
        $fileCreationInformation = new FileCreationInformation();
        $fileCreationInformation->Content = $content;
        $fileCreationInformation->Url = $this->getFilenameForPath($path);

        $uploadFile = $folder->getFiles()
                             ->add($fileCreationInformation);

        $connector->executeQuery();

        return $uploadFile;
    }

    private function setupClient()
    {
        $this->client = (new ClientContext($this->settings['url']))->withCredentials($this->auth);
    }
}
