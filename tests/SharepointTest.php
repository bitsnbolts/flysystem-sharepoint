<?php
namespace BitsnBolts\Flysystem\Sharepoint\Test;

use League\Flysystem\Filesystem;
use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use League\Flysystem\StorageAttributes;
use Office365\Runtime\Http\RequestException;

class SharepointTest extends TestBase
{
    private $fs;
    private $adapter;

    private $filesToPurge = [];
    private $directoriesToPurge = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new SharepointAdapter([
            'url' => SHAREPOINT_SITE_URL,
            'username' => SHAREPOINT_USERNAME,
            'password' => SHAREPOINT_PASSWORD,
        ], TEST_FILE_PREFIX);

        $this->fs = new Filesystem($this->adapter);
    }

    /** @group write */
    public function testWrite()
    {
        $this->fs->write('testWrite.txt', 'testing');
        $fileExists = $this->fs->fileExists('testWrite.txt');
        $contents = $this->fs->read('testWrite.txt');

        $this->assertTrue($fileExists);
        $this->assertEquals('testing', $contents);
        $this->filesToPurge[] = 'testWrite.txt';
    }

    public function testWriteToDirectory()
    {
        $this->fs->write('testDir/testWriteInDir.txt', 'testing');
        $fileExists = $this->fs->fileExists('testDir/testWriteInDir.txt');
        $contents = $this->fs->read('testDir/testWriteInDir.txt');

        $this->assertTrue($fileExists);
        $this->assertEquals('testing', $contents);

        $this->filesToPurge[] = 'testDir/testWriteInDir.txt';
        $this->directoriesToPurge[] = 'testDir';
    }

    /** @group nest */
    public function testWriteToNestedDirectory()
    {
        $this->markTestSkipped('This does not work yet');

        $this->assertEquals(true, $this->fs->write('testDir/nested/testWriteInDir.txt', 'testing'));
        $this->filesToPurge[] = 'testDir/testWriteInDir.txt';
        $this->directoriesToPurge[] = 'testDir/nested';
        $this->directoriesToPurge[] = 'testDir';
    }

    public function testWriteStream()
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'testing');
        rewind($stream);

        $this->fs->writeStream('testWriteStream.txt', $stream);
        $fileExists = $this->fs->fileExists('testWriteStream.txt');
        $contents = $this->fs->read('testWriteStream.txt');

        $this->assertTrue($fileExists);
        $this->assertEquals('testing', $contents);
        $this->filesToPurge[] = 'testWriteStream.txt';
    }

    public function testRead()
    {
        $this->fs->write('testRead.txt', 'read content');
        $this->filesToPurge[] = 'testRead.txt';

        $this->assertEquals('read content', $this->fs->read('testRead.txt'));
    }

    /** @group read */
    public function testReadInDirectory()
    {
        $this->fs->write('testDir/testReadInDir.txt', 'read content in directory');
        $this->filesToPurge[] = 'testDir/testReadInDir.txt';
        $this->directoriesToPurge[] = 'testDir';

        $this->assertEquals('read content in directory', $this->fs->read('testDir/testReadInDir.txt'));
    }

    /** @group word */
    public function testReadWord()
    {
        $path = __DIR__ . '/files/word.docx';
        $this->fs->writeStream('testWord.docx', fopen($path, 'r'));
        $this->filesToPurge[] = 'testWord.docx';

        $this->assertNotEmpty($this->fs->read('testWord.docx'));
    }


    public function testDelete()
    {
        // Create file
        $this->fs->write('testDelete.txt', 'testing');
        // Ensure it exists
        $this->assertEquals(true, $this->fs->fileExists('testDelete.txt'));
        // Now delete
        $this->fs->delete('testDelete.txt');
        // Ensure it no longer exists
        $this->assertEquals(false, $this->fs->fileExists('testDelete.txt'));
    }

    /** @group del */
    public function testDeleteDirectory()
    {
        // Create directory
        $result = $this->fs->createDirectory('delete-dir');
        // Ensure it exists
        $this->assertEquals(true, $this->fs->directoryExists('delete-dir'));
        // Now delete
        $this->fs->delete('delete-dir');
        // Ensure it no longer exists
        $this->assertEquals(false, $this->fs->directoryExists('delete-dir'));
    }

    public function testFileExists()
    {
        // Test that file does not exist
        $this->assertEquals(false, $this->fs->fileExists('testHas.txt'));

        // Create file
        $this->createFile('testHas.txt');

        // Test that file exists
        $this->assertEquals(true, $this->fs->fileExists('testHas.txt'));
    }

    /** @group has */
    public function testHasInFolder()
    {
        // Test that file does not exist
        $this->assertEquals(false, $this->fs->fileExists('folder/testHasInFolder.txt'));

        // Create file
        $this->createFile('folder/testHasInFolder.txt');

        // Test that file exists
        $this->assertEquals(true, $this->fs->fileExists('folder/testHasInFolder.txt'));
    }

    public function testListContents()
    {
        // Create files
        $this->createFile('first.txt');
        $this->createFile('second.txt');

        [$first, $second] = $this->fs->listContents('.')->sortByPath()->toArray();

        $this->assertEquals('first.txt', basename($first->path()));
        $this->assertEquals('second.txt', basename($second->path()));
    }

    public function testListContentsContainsDirectories()
    {
        // Create files
        $this->createFile('file.txt');
        $this->createFile('test-list-contents-contains-directory/in-folder.txt');

        [$first, $directory] = $this->fs->listContents('.')->sortByPath()->toArray();

        $this->assertEquals('file.txt', basename($first->path()));
        $this->assertEquals('test-list-contents-contains-directory', basename($directory->path()));
    }


    /** @group thijs2 */
    public function testListContentsOfDirectory()
    {
        // Create files
        $this->createFile('list-directory/ld-first.txt');
        $this->createFile('list-directory/ld-second.txt');

        try {
            [$first, $second] = $this->fs->listContents('list-directory')->sortByPath()->toArray();

            $this->assertEquals('ld-first.txt', basename($first->path()));
            $this->assertEquals('ld-second.txt', basename($second->path()));
        } catch (RequestException $e) {
            $this->fail($e->getMessage());
        }
    }

    /** @group rec */
    public function testListContentsRecursive()
    {
        // Create files
        $this->createFile('1-root-first.txt');
        $this->createFile('2-list-recursive/3-recursive-first.txt');
        $this->createDir('4-empty-dir');

        // More then one level fails ATM.
        // $this->createFile('list-recursive/nested/recursive-second.txt');


        $result = $this->fs->listContents('.', true)->toArray();
        [$first, $directory, $nested, $emptyDir] = $this->fs->listContents('.', true)->sortByPath()->toArray();

        $this->assertEquals('1-root-first.txt', basename($first->path()));
        $this->assertEquals('2-list-recursive', basename($directory->path()));
        $this->assertEquals('3-recursive-first.txt', basename($nested->path()));
        $this->assertEquals('4-empty-dir', basename($emptyDir->path()));
    }



    public function testGetUrl()
    {
        // Create file
        $this->createFile('testGetUrl.txt');

        // Get url
        $this->assertNotEmpty($this->adapter->getUrl('testGetUrl.txt'));
    }

    public function testTimestamp()
    {
        // Create file
        $this->createFile('testTimestamp.txt');

        // Call metadata
        $this->assertIsInt($this->fs->lastModified('testTimestamp.txt'));
    }

    public function testMimetype()
    {
        $this->markTestSkipped('SPO doesnt return a mimetype');

        // Create file
        $this->createFile('testMimetype.txt');

        // Call metadata
        $this->assertEquals('text/plain', $this->fs->getMimetype('testMimetype.txt'));
    }

    public function testSize()
    {
        // Create file
        $this->createFile('testSize.txt', 'testing metadata functionality');

        // Get the file size
        $this->assertEquals(30, $this->fs->fileSize('testSize.txt'));
    }

    /**
     * @return void
     */
    public function testLargeFileUploads()
    {
        // Create file
        $path = __DIR__ . '/files/50MB.bin';
        $this->fs->writeStream('testLargeUpload.txt', fopen($path, 'r'));
        // fclose($path);
        $this->filesToPurge[] = 'testLargeUpload.txt';

        // Get the file size
        $this->assertEquals(50000000, $this->fs->fileSize('testLargeUpload.txt'));
    }

    public function testListContentsForNonExistingDirectoriesReturnAnEmptyArray()
    {
        $result = $this->fs->listContents('non-existing-directory')->toArray();
        $this->assertEquals([], $result);
    }

    protected function createFile($path, $content = '::content::')
    {
        $this->fs->write($path, $content);
        $this->filesToPurge[] = $path;

        if (strpos($path, '/')) {
            $dir = $path;
            while (dirname($dir) !== '.') {
                $dir = dirname($dir);
                $this->directoriesToPurge[] = $dir;
            }
        }
    }

    public function createDir($path)
    {
        $this->fs->createDirectory($path);
        $this->directoriesToPurge[] = $path;
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

    protected function clearStorage()
    {
        $result = $this->fs->listContents('', true);
        $listing = $result->toArray();

        usort($listing, function (StorageAttributes $a, StorageAttributes $b) {
            return $a->isDir() - $b->isDir();
        });

        foreach ($listing as $item) {
            if ($item->isDir()) {
                $this->fs->deleteDirectory($item->path());
            } else {
                $this->fs->delete($item->path());
            }
        }
    }
}
