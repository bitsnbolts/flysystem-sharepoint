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

	public function handle($email = null, $path = null)
	{
		$adapter = $this->filesystem->getAdapter();
		$adapter->grantUserAccessToPath($email, $path);
	}
}