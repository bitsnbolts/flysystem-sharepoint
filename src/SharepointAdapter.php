<?php

namespace BitsnBolts\Flysystem\Sharepoint;

use Exception;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use Office365\PHP\Client\Runtime\Auth\AuthenticationContext;
use Office365\PHP\Client\SharePoint\ClientContext;
use Office365\PHP\Client\SharePoint\ListTemplateType;
use Office365\PHP\Client\SharePoint\ListCreationInformation;
use Office365\PHP\Client\SharePoint\FileCreationInformation;
use Office365\PHP\Client\SharePoint\File;

class SharepointAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var ClientContext
     */
    protected $client;

	/**
	 * @var AuthenticationContext
	 */
    protected $auth;

    /**
     * @var string[]
     */
    protected static $metaOptions = [
        'CacheControl',
        'ContentType',
        'Metadata',
        'ContentLanguage',
        'ContentEncoding',
    ];

    /**
     * Constructor.
     *
     * @param ClientContext  $sharepointClient
     * @param string $prefix
     */
    public function __construct($settings, $prefix = null)
    {
    	$this->authorize($settings);
	    $this->client = new ClientContext($settings['url'], $this->auth);
        $this->setPathPrefix($prefix);
    }

    private function showList($listTitle)
    {
	    $lists = $this->client->getWeb()->getLists()->filter('Title eq \''. $listTitle . '\'')->top(1);
	    $this->client->load($lists);
	    $this->client->executeQuery();
	    $listData = $lists->getData();
	    if (!count($listData)) {
	    	return array();
	    }
	    $list = $listData[0];
	    $items = $list->getItems();
	    $this->client->load($items);
	    $this->client->executeQuery();
	    foreach( $items->getData() as $item ) {
//		    print "Task: '{$item->Title}'\r\n";
	    }
	    return $items->getData();
    }

    private function getList($path)
    {
    	$listTitle = $this->getListTitleForPath($path);
	    $lists = $this->client->getWeb()->getLists()->filter('Title eq \''. $listTitle . '\'')->top(1);
	    $this->client->load($lists);
	    $this->client->executeQuery();
	    $listData = $lists->getData();
	    if (count($listData) === 0) {
	    	throw new ListNotFoundException();
	    }
	    $list = $listData[0];
	    return $list;
    }

    private function getListTitleForPath($path)
    {
	    return current(explode('/',$path));
    }

    private function getFilenameForPath($path)
    {
	    $parts = explode( '/', $path );

	    $filename = end( $parts );

	    return $filename;
    }

	/**
	 * @param $path
	 *
	 * @return mixed
	 */
	protected function getFileByPath( $path )
	{
		$list = $this->getList( $path );
		// @todo make this dynamic based on the path.
		$items = $list->getItems();

		$filename = $this->getFilenameForPath( $path );
		$items->filter( 'Title eq \'' . $filename . '\'')->top(1);
		$this->client->load( $items );
		$this->client->executeQuery();
		if ($items->getCount() === 0) {
			throw new FileNotFoundException($path);
		}
		$item = $items->getItem(0);
		$file = $item->getFile();
		$this->client->load( $file );

		try {
			$this->client->executeQuery();
		} catch (Exception $exception) {
			throw new FileNotFoundException($path);
		}

		return $file;
	}

	private function printLists()
    {
	    $lists = $this->client->getWeb()->getLists();
	    $this->client->load($lists);
	    $this->client->executeQuery();
	    foreach( $lists->getData() as $list ) {
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

    private function addFileToList($path, $upload)
    {
    	try {
    		$list = $this->getList($path);
	    } catch (ListNotFoundException $e) {
			$list = $this->createList($this->getListTitleForPath($path));
	    }
	    $connector = $list->getContext();

	    $fileCreationInformation = new FileCreationInformation();
	    $fileCreationInformation->Content = file_get_contents($upload->getFileName());
	    $fileCreationInformation->Url = basename(str_replace('\'', '\'\'', $upload->getFileName()));

	    $uploadFile = $list->getRootFolder()->getFiles()->add($fileCreationInformation);

	    $connector->executeQuery();

	    $uploadFile->getListItemAllFields()->setProperty('Title', basename(str_replace('\'', '\'\'', $upload->getFileName())));
	    $uploadFile->getListItemAllFields()->update();

	    $connector->executeQuery();

	    return $uploadFile;
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
        return $this->upload($path, $resource, $config);
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
        return $this->upload($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $this->copy($path, $newpath);

        return $this->delete($path);
    }

    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        // @todo: implement the copy action.
        // $this->client->copyBlob($this->container, $newpath, $this->container, $path);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);
		$file = $this->getFileByPath($path);
		$file->recycle();
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
    	$this->createList($dirname);
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
	    try {
		    $this->getFileByPath($path);
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
	    $file = $this->getFileByPath( $path);
	    $fileContent = File::openBinary($this->client, $file->getProperty('ServerRelativeUrl') );
	    $response = array('contents' => $fileContent);
	    return $response;
    }

	/**
	 * Open this file by redirecting the user to sharepoint.
	 * @param $path
	 */
    public function open($path)
    {
		die('foo');
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
		$listing = array($this->showList($directory));
		$normalizer = [$this, 'normalizeResponse'];
		$paths = array($directory);
		$normalized = array_map($normalizer, $listing, $paths);
        return Util::emulateDirectories($normalized);
    }

	/**
	 * Normalize the object result array.
	 *
	 * @param array  $response
	 * @param string $path
	 *
	 * @return array
	 */
	protected function normalizeResponse(array $response, $path = null)
	{
		if (substr($path, -1) === '/') {
			return ['type' => 'dir', 'path' => $this->removePathPrefix(rtrim($path, '/'))];
		}

		$path = $this->removePathPrefix($path);


		$item = $response[0];
		$modified = date_create( $item->getProperty('TimeLastModified'))->format('U');

		return [
			'path' => $item->getProperty('ServerRelativeUrl'),
			'linkingUrl' => $item->getProperty('LinkingUrl'),
			'timestamp' => (int) $modified,
			'dirname' => Util::dirname($path[0]),
			'mimetype' => '',
			'size' => 12,
			'type' => 'file',
		];
	}

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

		$file = array($this->getFileByPath($path));
		return $this->normalizeResponse($file, 'foobar');
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
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
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
            'path' => $path,
            'timestamp' => (int) $timestamp,
            'dirname' => Util::dirname($path),
            'type' => 'file',
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
    protected function normalizeBlobProperties($path, BlobProperties $properties)
    {
        if (substr($path, -1) === '/') {
            return ['type' => 'dir', 'path' => $this->removePathPrefix(rtrim($path, '/'))];
        }

        $path = $this->removePathPrefix($path);

        return [
            'path' => $path,
            'timestamp' => (int) $properties->getLastModified()->format('U'),
            'dirname' => Util::dirname($path),
            'mimetype' => $properties->getContentType(),
            'size' => $properties->getContentLength(),
            'type' => 'file',
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
        return ['type' => 'dir', 'path' => $this->removePathPrefix(rtrim($blobPrefix->getName(), '/'))];
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
        $listTitle = $this->getListTitleForPath($path);
        $result = $this->addFileToList($path, $contents);
        $properties = $result->getProperties();
        $modified = date_create($properties['TimeLastModified'])->format('U');
        return $this->normalize($properties['ServerRelativeUrl'], $modified, $contents);
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

    protected function authorize($settings)
    {
		$this->auth = new AuthenticationContext( $settings['url'] );
		$this->auth->acquireTokenForUser( $settings['username'], $settings['password'] );
    }
}
