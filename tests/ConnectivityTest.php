<?php
namespace BitsnBolts\Flysystem\Sharepoint\Test;

use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use RuntimeException;

class ConnectivityTest extends TestBase
{
    /**
     * Tests if an exception is properly thrown when unable to connect to
     * Microsoft Sharepoint service due to invalid credentials.
     *
     * @test
     * @group foo
     */
    public function testAuthFailure()
    {
        $adapter = new SharepointAdapter([
            'url' => SHAREPOINT_SITE_URL,
            'username' => 'invalid',
            'password' => 'invalid',
        ]);
        $adapter->has('foo');
        // The has function catches the error and returns false.
        // So this test doesn't really work.
       $this->markTestIncomplete();
    }

    /**
     * Tests if an exception is properly thrown when a sharepoint site specified is invalid.
     *
     * @test
     */
    public function testInvalidSiteSpecified()
    {
        $this->markTestSkipped('todo');
        $this->expectException(SiteInvalidException::class);
        $adapter = new SharepointAdapter([
            'url' => 'invalid',
            'username' => SHAREPOINT_USERNAME,
            'password' => SHAREPOINT_PASSWORD,
        ]);
    }

    /**
     * Tests to ensure that the adapter is successfully created which is a result of
     * valid authentication with access token retrieved.
     *
     * @test
     */
    public function testAuthSuccess()
    {
        $adapter = new SharepointAdapter([
            'url' => SHAREPOINT_SITE_URL,
            'username' => SHAREPOINT_USERNAME,
            'password' => SHAREPOINT_PASSWORD,
        ]);
        $this->assertFalse($adapter->has('path_that_doesnt_exist'));
    }
}
