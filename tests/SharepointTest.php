<?php
namespace BitsnBolts\Flysystem\Sharepoint\Test;

use League\Flysystem\Filesystem;
use BitsnBolts\Flysystem\Sharepoint\GetUrl;
use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use Office365\Runtime\Http\RequestException;

class SharepointTest extends TestBase
{
    private $fs;

    private $filesToPurge = [];
    private $directoriesToPurge = [];

    protected function setUp(): void
    {
        parent::setUp();
        $adapter = new SharepointAdapter([
            'url' => SHAREPOINT_SITE_URL,
            'username' => SHAREPOINT_USERNAME,
            'password' => SHAREPOINT_PASSWORD,
        ]);

        $this->fs = new Filesystem($adapter);
    }

    /** @group write */
    public function testWrite()
    {
        $this->assertEquals(true, $this->fs->write(TEST_FILE_PREFIX . 'testWrite.txt', 'testing'));
        $this->filesToPurge[] = TEST_FILE_PREFIX . 'testWrite.txt';
    }

    public function testWriteToDirectory()
    {
        $this->assertEquals(true, $this->fs->write(TEST_FILE_PREFIX . 'testDir/testWriteInDir.txt', 'testing'));
        $this->filesToPurge[] = TEST_FILE_PREFIX . 'testDir/testWriteInDir.txt';
        $this->directoriesToPurge[] = TEST_FILE_PREFIX . 'testDir';
    }

    public function testWriteStream()
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'testing');
        rewind($stream);

        $this->assertEquals(true, $this->fs->writeStream(TEST_FILE_PREFIX . 'testWriteStream.txt', $stream));
        $this->filesToPurge[] = TEST_FILE_PREFIX . 'testWriteStream.txt';
    }

    public function testDelete()
    {
        // Create file
        $this->fs->write(TEST_FILE_PREFIX . 'testDelete.txt', 'testing');
        // Ensure it exists
        $this->assertEquals(true, $this->fs->has(TEST_FILE_PREFIX . 'testDelete.txt'));
        // Now delete
        $this->assertEquals(true, $this->fs->delete(TEST_FILE_PREFIX . 'testDelete.txt'));
        // Ensure it no longer exists
        $this->assertEquals(false, $this->fs->has(TEST_FILE_PREFIX . 'testDelete.txt'));
    }

    /** @group del */
    public function testDeleteDirectory()
    {
        // Create directory
        $result = $this->fs->createDir(TEST_FILE_PREFIX . 'delete-dir');
        // Ensure it exists
        $this->assertEquals(true, $this->fs->has(TEST_FILE_PREFIX . 'delete-dir'));
        // Now delete
        $this->assertEquals(true, $this->fs->delete(TEST_FILE_PREFIX . 'delete-dir'));
        // Ensure it no longer exists
        $this->assertEquals(false, $this->fs->has(TEST_FILE_PREFIX . 'delete-dir'));
    }

    public function testHas()
    {
        // Test that file does not exist
        $this->assertEquals(false, $this->fs->has(TEST_FILE_PREFIX . 'testHas.txt'));

        // Create file
        $this->createFile('testHas.txt');

        // Test that file exists
        $this->assertEquals(true, $this->fs->has(TEST_FILE_PREFIX . 'testHas.txt'));
    }

    /** @group has */
    public function testHasInFolder()
    {
        // Test that file does not exist
        $this->assertEquals(false, $this->fs->has(TEST_FILE_PREFIX . 'folder/testHasInFolder.txt'));

        // Create file
        $this->createFile('folder/testHasInFolder.txt');

        // Test that file exists
        $this->assertEquals(true, $this->fs->has(TEST_FILE_PREFIX . 'folder/testHasInFolder.txt'));
    }

    public function testListContents()
    {
        // Create files
        $this->createFile('first.txt');
        $this->createFile('second.txt');

        [$first, $second] = $this->fs->listContents(TEST_FILE_PREFIX);

        $this->assertEquals('first.txt', $first['basename']);
        $this->assertEquals('second.txt', $second['basename']);
    }

    /** @group foo */
    public function testListContentsContainsDirectories()
    {
        // Create files
        $this->createFile('file.txt');
        $this->createFile('test-list-contents-contains-directory/in-folder.txt');

        [$first, $directory] = $this->fs->listContents(TEST_FILE_PREFIX);

        $this->assertEquals('file.txt', $first['basename']);
        $this->assertEquals('test-list-contents-contains-directory', $directory['basename']);
    }


    /** @group thijs2 */
    public function testListContentsOfDirectory()
    {
        // Create files
        $this->createFile('list-directory/ld-first.txt');
        $this->createFile('list-directory/ld-second.txt');

        try {
            [$first, $second] = $this->fs->listContents(TEST_FILE_PREFIX . 'list-directory');

            $this->assertEquals('ld-first.txt', $first['basename']);
            $this->assertEquals('ld-second.txt', $second['basename']);
        } catch (RequestException $e) {
            $this->fail($e->getMessage());
        }
    }


    public function testGetUrl()
    {
        // Create file
        $this->createFile('testGetUrl.txt');

        // Get url
        $this->assertNotEmpty($this->fs->getAdapter()->getUrl(TEST_FILE_PREFIX . 'testGetUrl.txt'));
    }

    public function testGetUrlPlugin()
    {
        $this->fs->addPlugin(new GetUrl());

        $this->createFile('testGetUrlPlugin.txt');

        // Get url
        $this->assertNotEmpty($this->fs->getAdapter()->getUrl(TEST_FILE_PREFIX . 'testGetUrlPlugin.txt'));
    }

    public function testGetMetadata()
    {
        // Create file
        $this->createFile('testMetadata.txt');

        // Call metadata
        $metadata = $this->fs->getMetadata(TEST_FILE_PREFIX.'testMetadata.txt');
        $this->assertEquals(TEST_FILE_PREFIX.'testMetadata.txt', $metadata['path']);
    }

    public function testTimestamp()
    {
        // Create file
        $this->createFile('testTimestamp.txt');

        // Call metadata
        $this->assertIsInt($this->fs->getTimestamp(TEST_FILE_PREFIX.'testTimestamp.txt'));
    }

    public function testMimetype()
    {
        $this->markTestSkipped('SPO doesnt return a mimetype');

        // Create file
        $this->createFile('testMimetype.txt');

        // Call metadata
        $this->assertEquals('text/plain', $this->fs->getMimetype(TEST_FILE_PREFIX.'testMimetype.txt'));
    }

    public function testSize()
    {
        // Create file
        $this->createFile('testSize.txt', 'testing metadata functionality');

        // Get the file size
        $this->assertEquals(30, $this->fs->getSize(TEST_FILE_PREFIX.'testSize.txt'));
    }

    /**
     * @return void
     */
    public function testLargeFileUploads()
    {
        // Create file
        $path = __DIR__ . '/files/50MB.bin';
        $this->fs->writeStream(TEST_FILE_PREFIX . 'testLargeUpload.txt', fopen($path, 'r'));
        // fclose($path);
        $this->filesToPurge[] = TEST_FILE_PREFIX . 'testLargeUpload.txt';

        // Get the file size
        $this->assertEquals(30, $this->fs->getSize(TEST_FILE_PREFIX.'testLargeUpload.txt'));
    }

    protected function createFile($path, $content = '::content::')
    {
        $this->fs->write(TEST_FILE_PREFIX . $path, $content);
        $this->filesToPurge[] = TEST_FILE_PREFIX . $path;

        if (strpos($path, '/')) {
            $dir = $path;
            while(dirname($dir) !== '.') {
                $dir = dirname($dir);
                $this->directoriesToPurge[] = TEST_FILE_PREFIX . $dir;
            }
        }
        ray($this->directoriesToPurge);
    }

    /**
     * Tears down the test suite by attempting to delete all files written, clearing things up
     *
     * @todo Implement functionality
     */
    protected function tearDown(): void
    {
        foreach ($this->filesToPurge as $path) {
            try {
                $this->fs->delete($path);
            } catch (\Exception $e) {
                echo 'file purge failed: ' .$e->getMessage();
                // Do nothing, just continue. We obviously can't clean it
            }
        }
        $this->filesToPurge = [];

        // Deleting directories doensnt work.
        // @see https://github.com/bitsnbolts/flysystem-sharepoint/issues/6

        foreach ($this->directoriesToPurge as $path) {
            try {
                $this->fs->delete($path);
            } catch (\Exception $e) {
                echo 'dir purge failed: ' .$e->getMessage();
                // Do nothing, just continue. We obviously can't clean it
            }
        }
        $this->directoriesToPurge = [];
    }
}
