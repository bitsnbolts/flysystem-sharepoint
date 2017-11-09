<?php

namespace BitsnBolts\Flysystem\Sharepoint;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Office365\PHP\Client\SharePoint\ClientContext;

class SharepointAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var string
     */
    protected $container;

    /**
     * @var IBlob
     */
    protected $client;

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
     * @param string $container
     * @param string $prefix
     */
    public function __construct(ClientContext $sharepointClient, $container, $prefix = null)
    {
        $this->client = $sharepointClient;
        $this->container = $container;
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

        // @todo: Implement the delete action.
        // $this->client->deleteBlob($this->container, $path);

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
        $this->write(rtrim($dirname, '/') . '/', ' ', $config);

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            // @todo: implement the has action.
            // $this->client->getBlobMetadata($this->container, $path);
        } catch (ServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

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
        // @todo
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

        // Append trailing slash only for directory other than root (which after normalization is an empty string).
        // Listing for / doesn't work properly otherwise.
        if (strlen($directory)) {
            $directory = rtrim($directory, '/') . '/';
        }


        // @todo
        return Util::emulateDirectories($contents);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        // @todo
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

        // @todo
        /** @var CopyBlobResult $result */
        // $result = $this->client->createBlockBlob(
        //     $this->container,
        //     $path,
        //     $contents,
        //     $this->getOptionsFromConfig($config)
        // );

        return $this->normalize($path, $result->getLastModified()->format('U'), $contents);
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
}
