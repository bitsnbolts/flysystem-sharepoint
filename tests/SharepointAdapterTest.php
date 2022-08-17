<?php

namespace BitsnBolts\Flysystem\Sharepoint\Test;

use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;

// Include our configuration
include_once("config.php");

class SharepointAdapterTest extends FilesystemAdapterTestCase
{

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new SharepointAdapter([
            'url' => SHAREPOINT_SITE_URL,
            'username' => SHAREPOINT_USERNAME,
            'password' => SHAREPOINT_PASSWORD,
        ], 'apitest2/');
    }

    public function clearStorage(): void
    {
        try {
            $adapter = $this->adapter();
        } catch (Throwable $exception) {
            /*
             * Setting up the filesystem adapter failed. This is OK at this stage.
             * The exception will have been shown to the user when trying to run
             * a test. We expect an exception to be thrown when tests are marked as
             * skipped when a filesystem adapter cannot be constructed.
             */
            return;
        }
        foreach($adapter->listContents('', true) as $item) {
            $listing[] = $item;
        }

        usort($listing, function (StorageAttributes $a, StorageAttributes $b) {
            return $a->isDir() - $b->isDir();
        });

        foreach ($listing as $item) {
            if ($item->isDir()) {
                $adapter->deleteDirectory($item->path());
            } else {
                $adapter->delete($item->path());
            }
        }
    }
}