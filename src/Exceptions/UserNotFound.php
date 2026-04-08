<?php

namespace BitsnBolts\Flysystem\Sharepoint\Exceptions;

use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

class UserNotFound extends RuntimeException implements FilesystemException
{
    public static function withLoginName(string $loginName, ?Throwable $previous = null): self
    {
        return new self(
            "SharePoint user '{$loginName}' was not found in Office 365.",
            0,
            $previous
        );
    }
}
