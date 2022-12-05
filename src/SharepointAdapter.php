<?php

namespace BitsnBolts\Flysystem\Sharepoint;

use Exception;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\Config;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Office365\SharePoint\File;
use Office365\SharePoint\Folder;
use Office365\Runtime\Http\HttpMethod;
use Office365\SharePoint\ClientContext;
use Office365\Runtime\Http\RequestOptions;
use Office365\SharePoint\ListTemplateType;
use Office365\Runtime\Auth\UserCredentials;
use Office365\SharePoint\FileCreationInformation;
use Office365\SharePoint\ListCreationInformation;
use Office365\Runtime\Http\RequestException;
use Office365\SharePoint\SPResourcePath;

class SharepointAdapter implements FilesystemAdapter
{
    protected ClientContext $client;

    protected UserCredentials $auth;

    protected array $settings;

    private PathPrefixer $prefixer;

    protected array $fileCache = [];
    protected array $listCache = [];
    protected array $folderCache = [];

    /**
     * @var string[]
     */
    private const META_OPTIONS = [
            'CacheControl',
            'ContentType',
            'Metadata',
            'ContentLanguage',
            'ContentEncoding',
    ];

    public function __construct(array $settings, string $prefix = '')
    {
        $this->settings = $settings;
        $this->authorize();
        $this->setupClient();
        $this->prefixer = new PathPrefixer($prefix);
    }

    public function getClient(): ClientContext
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        $path = $this->prefixer->prefixPath($path);
        try {
            $this->getFileByPath($path, true);
        } catch (UnableToReadFile $e) {
            return false;
        }

        return true;
    }

    public function directoryExists(string $path): bool
    {
        $path = $this->prefixer->prefixDirectoryPath($path);
        try {
            $this->getFolderForPath($path, $this->getList($path), false, true);
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, stream_get_contents($contents), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        $path = $this->prefixer->prefixPath($path);
        $file = $this->getFileByPath($path);
        $fileContent = File::openBinary(
            $this->client,
            $file->getProperty('ServerRelativeUrl')
        );
        return $fileContent;
        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $path = $this->prefixer->prefixPath($path);
        $file = $this->getFileByPath($path);
        $fileName = implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), $file->getName()]);
        $fh = fopen($fileName, 'w+');
        return $file->download($fh);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): void
    {
        $path = $this->prefixer->prefixPath($path);

        if ($this->isFolder($path)) {
            $folder = $this->getFolderForPath($path, $this->getList($path), false, true);
            $folder->recycle();
        } else {
            try {
                $file = $this->getFileByPath($path, true);
                $file->recycle();
            } catch (FileNotFoundException $e) {
                throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
            }
        }

        $this->client->executeQuery();
    }

    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->prefixer->prefixDirectoryPath($path);
        if (dirname($path) === '.') {
            $this->createList($path);
        } else {
            $directories = explode('/', $path);
            $list = $this->getList(array_shift($directories));
            $this->createFolderInList($list, implode('/', $directories));
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        // Noop
        return new FileAttributes($path);
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->iterateFolderContents($path, $deep) as $entry) {
            $storageAttrs = $this->normalizeResponse($entry);

            // Avoid including the base directory itself
            if ($storageAttrs->isDir() && $storageAttrs->path() === $path) {
                continue;
            }

            yield $storageAttrs;
        }
    }

    protected function normalizeResponse($response): StorageAttributes
    {
        return match (get_class($response)) {
            File::class => $this->normalizeFileResponse($response),
            Folder::class => $this->normalizeFolderResponse($response)
        };
    }

    protected function iterateFolderContents(string $path = '', bool $deep = false): \Generator
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        try {
            $listing = $this->showList($location);
        } catch (RequestException $e) {
            $message = json_decode($e->getMessage());
            if (strpos($message->error->code, 'System.IO.DirectoryNotFoundException')) {
                return [];
            }
            throw $e;
        }

        yield from $listing;

        try {
            $folders = $this->showListFolders($location);
        } catch (RequestException $e) {
            $message = json_decode($e->getMessage());
            if (strpos($message->error->code, 'System.IO.DirectoryNotFoundException')) {
                return [];
            }
            throw $e;
        }

        $folders = array_filter($folders, fn ($folder) => $folder->getName() !== 'Forms');
        yield from $folders;

        if ($deep) {
            foreach ($folders as $folder) {
                try {
                    $listing = $this->showList($location . $folder->getName());
                } catch (RequestException $e) {
                    $message = json_decode($e->getMessage());
                    if (strpos($message->error->code, 'System.IO.DirectoryNotFoundException')) {
                        return [];
                    }
                    throw $e;
                }

                yield from $listing;
            }
        }
    }


    public function move(string $source, string $destination, Config $config): void
    {
        $file = $this->getFileByPath($source);
        $file->moveTo($destination, 1);
        $this->client->executeQuery();
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $path = $this->prefixer->prefixPath($source);
        $newpath = $this->prefixer->prefixPath($destination);

        // @todo.
//        $file = $this->getFileByPath($path);
//        $file->moveTo($newpath);
//        $this->client->executeQuery();
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
     * @throws UnableToReadFile
     */
    public function getUrl($path)
    {
        $path = $this->prefixer->prefixPath($path);
        $file = $this->getFileByPath($path);
        if (!$file) {
            throw UnableToReadFile::fromLocation($path);
        }
        if ($file->getLinkingUri()) {
            return $file->getLinkingUri();
        }

        $listItem = $file->getListItemAllFields();
        $this->client->load($listItem, ['EncodedAbsUrl']);
        $this->client->executeQuery();

        // re-encode the url to fix encoding for ë like characters.
        $parsed = parse_url(rawurldecode($listItem->getProperty('EncodedAbsUrl')));
        return sprintf('%s://%s/%s', $parsed['scheme'], $parsed['host'], rawurlencode(substr($parsed['path'], 1)));
    }

    protected function getDirectoryContents(string $directory, bool $deep)
    {
        $directory = $this->prefixer->prefixDirectoryPath($directory);
        try {
            $listing = $this->showList($directory);
        } catch (RequestException $e) {
            $message = json_decode($e->getMessage());
            if (strpos($message->error->code, 'System.IO.DirectoryNotFoundException')) {
                return [];
            }
            throw $e;
        }

        if (count($listing) === 0) {
            return [];
        }

        $normalizer = [$this, 'normalizeFileResponse'];
        $paths = array_fill(0, count($listing), $directory);
        $normalized = array_map($normalizer, $listing, $paths);

        $folders = $this->showListFolders($directory);
        $folders = array_filter($folders, fn ($folder) => $folder->getName() !== 'Forms');

        $folderNormalizer = [$this, 'normalizeFolderResponse'];
        $paths = array_fill(0, count($folders), $directory);
        $normalizedFolder = array_map($folderNormalizer, $folders, $paths);

        $dirs = array_filter($normalizedFolder, fn ($item) => $item->extraMetadata()['type'] === 'dir');

        $nested = [];
        if ($deep) {
            $nested = array_reduce($dirs, function ($carry, $folder) {
                $dirContents = $this->getDirectoryContents($folder['path'], true);
                if (count($dirContents) === 0) {
                    return $carry;
                }
                $carry = array_merge($carry, $dirContents);
                return $carry;
            }, []);
        }

        return array_merge($normalized, $normalizedFolder, $nested);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path): FileAttributes|array
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            $file = $this->getFileByPath($path);
        } catch (UnableToReadFile $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage());
        }

        return $this->normalizeFileResponse($file, dirname($path));
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
     * Normalize the object result array.
     *
     * @param array  $response
     * @param string $path
     *
     * @return array
     */
    protected function normalizeFileResponse(File $item): FileAttributes
    {
        $modified = date_create($item->getTimeLastModified())->format('U');
        $created = date_create($item->getTimeCreated())->format('U');
        $path = str_replace(parse_url($this->client->getBaseUrl())['path'], '', $item->getServerRelativeUrl());
        $path = $this->prefixer->stripDirectoryPrefix($path);
        return new FileAttributes(
            $path,
            $item->getLength(),
            null,
            (int) $modified,
            '',
            [
                'linkingUrl' => $item->getLinkingUrl(),
                'created'  => (int) $created,
                'type'       => 'file'
            ]
        );
    }

    /**
     * Normalize the object result array.
     *
     * @param array  $response
     *
     * @return array
     */
    protected function normalizeFolderResponse(Folder $item): DirectoryAttributes
    {
        if (in_array($item->getName(), ['Forms'])) {
            return new DirectoryAttributes('', null, null, ['type' => 'other']);
        }

        $path = str_replace(parse_url($this->client->getBaseUrl())['path'], '', $item->getServerRelativeUrl());
        $path = $this->prefixer->stripDirectoryPrefix($path);

        $modified = date_create($item->getTimeLastModified())->format('U');
        $created = date_create($item->getTimeCreated())->format('U');

        return new DirectoryAttributes(
            $path,
            null,
            (int) $modified,
            [
                'created'  => (int) $created,
                'dirname'    => $path,
                'mimetype'   => '',
                'size'       => 0,
                'type'       => 'dir',
            ]
        );
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
            'dirname'   => $path,
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
                'path' => $this->prefixer->stripPrefix(rtrim($path, '/'))
            ];
        }

        $path = $this->prefixer->stripPrefix($path);

        return [
            'path'      => $path,
            'timestamp' => (int)$properties->getLastModified()->format('U'),
            'dirname'   => $path,
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
            'path' => $this->prefixer->stripPrefix(rtrim(
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
        $path = $this->prefixer->prefixPath($path);
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
        while ($folderName = array_shift($folders)) {
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
        $items = $this->client->getWeb();

        // get the right list.
        $folders = explode('/', $listTitle);
        while ($folderName = array_shift($folders)) {
            $items = $items->getFolders()
                ->getByUrl($folderName);
        }

        $items = $items
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
            throw UnableToReadFile::fromLocation($path);
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
     * @throws UnableToReadFile
     */
    private function getFileByPath($path, $fresh = false)
    {
        $path = str_replace("'", "''", $path);
        if (!$fresh && array_key_exists($path, $this->fileCache)) {
            return $this->fileCache[$path];
        }

        $targetFile = $this->client->getWeb()->getFileByServerRelativePath($this->toRelativePath($path));
        $this->client->load($targetFile);
        try {
            $this->client->executeQuery();
        } catch (RequestException $e) {
            throw UnableToReadFile::fromLocation(str_replace("''", "'", $path));
        }
        $this->fileCache[$path] = $targetFile;
        return $targetFile;
    }

    private function toRelativePath(string $path)
    {
        $serverRelativePath = parse_url($this->client->getBaseUrl())['path'] . '/' . $path;
        return new SPResourcePath($serverRelativePath);
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
               . "/_api/web/lists/getbytitle('{$listTitle}')/roleassignments/addroleassignment(principalid={$user->getId()},roledefid={$role->getId()})";

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
        } catch (UnableToReadFile $e) {
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
