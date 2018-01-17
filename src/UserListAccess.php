<?php

namespace BitsnBolts\Flysystem\Sharepoint;

use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;

class UserListAccess implements PluginInterface
{
    protected $filesystem;

    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function getMethod()
    {
        return 'grantUserAccess';
    }

    public function handle($loginName = null, $path = null)
    {
        $adapter = $this->filesystem->getAdapter();
        if (is_a($adapter, \League\Flysystem\Cached\CachedAdapter::class) && $adapter->getAdapter()) {
            $adapter = $adapter->getAdapter();
        }
        $adapter->grantUserAccessToPath($loginName, $path);
    }
}
