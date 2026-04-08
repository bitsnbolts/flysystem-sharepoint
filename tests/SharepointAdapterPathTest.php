<?php

namespace BitsnBolts\Flysystem\Sharepoint\Test;

use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use League\Flysystem\PathPrefixer;
use PHPUnit\Framework\TestCase;

class SharepointAdapterPathTest extends TestCase
{
    public function testNormalizesTopLevelLibraryWithoutTrailingSlash(): void
    {
        $adapter = $this->makeAdapterWithPrefix('');

        $this->assertSame('C898', $this->invoke($adapter, 'normalizeDirectoryPath', 'C898'));
        $this->assertSame('C898', $this->invoke($adapter, 'normalizeDirectoryPath', 'C898/'));
    }

    public function testTreatsSingleSegmentDirectoryAsLibrary(): void
    {
        $adapter = $this->makeAdapterWithPrefix('');

        $this->assertSame(['C898'], $this->invoke($adapter, 'getPathSegments', 'C898'));
        $this->assertSame(['C898'], $this->invoke($adapter, 'getPathSegments', 'C898/'));
        $this->assertSame('C898', $this->invoke($adapter, 'getListTitleForPath', 'C898/'));
        $this->assertSame('', $this->invoke($adapter, 'getFolderTitleForPath', 'C898/'));
    }

    public function testTreatsNestedDirectoryAsFolderWithinLibrary(): void
    {
        $adapter = $this->makeAdapterWithPrefix('');

        $this->assertSame('C898', $this->invoke($adapter, 'getListTitleForPath', 'C898/subfolder/'));
        $this->assertSame('subfolder', $this->invoke($adapter, 'getFolderTitleForPath', 'C898/subfolder/'));
        $this->assertSame('subfolder', $this->invoke($adapter, 'getFolderTitleForPath', 'C898/subfolder/file.txt'));
    }

    public function testNormalizesDuplicateSeparatorsAcrossParsingHelpers(): void
    {
        $adapter = $this->makeAdapterWithPrefix('prefix');

        $this->assertSame('prefix/C898', $this->invoke($adapter, 'normalizeDirectoryPath', '/C898//'));
        $this->assertSame(['prefix', 'C898', 'subfolder'], $this->invoke($adapter, 'getPathSegments', 'prefix/C898//subfolder/'));
        $this->assertSame('prefix', $this->invoke($adapter, 'getListTitleForGroupPath', 'prefix/C898//subfolder/'));
    }

    private function makeAdapterWithPrefix(string $prefix): SharepointAdapter
    {
        $reflection = new \ReflectionClass(SharepointAdapter::class);
        /** @var SharepointAdapter $adapter */
        $adapter = $reflection->newInstanceWithoutConstructor();

        $prefixer = $reflection->getProperty('prefixer');
        $prefixer->setAccessible(true);
        $prefixer->setValue($adapter, new PathPrefixer($prefix));

        return $adapter;
    }

    private function invoke(SharepointAdapter $adapter, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($adapter, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($adapter, ...$arguments);
    }
}
