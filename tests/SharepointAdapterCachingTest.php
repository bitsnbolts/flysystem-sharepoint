<?php

namespace BitsnBolts\Flysystem\Sharepoint\Test;

use BitsnBolts\Flysystem\Sharepoint\SharepointAdapter;
use PHPUnit\Framework\TestCase;

class SharepointAdapterCachingTest extends TestCase
{
    public function test_grant_user_access_breaks_role_inheritance_once_per_list(): void
    {
        $adapter = new TestableSharepointAdapter;

        $adapter->grantUserAccessToPath('first@example.test', 'cases/case-1');
        $adapter->grantUserAccessToPath('second@example.test', 'cases/case-2');

        $this->assertSame(1, $adapter->breakRoleInheritanceCalls);
        $this->assertSame(2, $adapter->buildAccessUrlCalls);
    }

    public function test_get_contributor_role_is_cached(): void
    {
        $adapter = new TestableSharepointAdapter;

        $adapter->resolveContributorRoleForTest();
        $adapter->resolveContributorRoleForTest();

        $this->assertSame(1, $adapter->resolveContributorRoleCalls);
    }

    public function test_users_are_cached_by_login_name(): void
    {
        $adapter = new TestableSharepointAdapter;

        $adapter->resolveUserForTest('user@example.test');
        $adapter->resolveUserForTest('user@example.test');
        $adapter->resolveUserForTest('other@example.test');

        $this->assertSame(2, $adapter->resolveUserCalls);
    }
}

class TestableSharepointAdapter extends SharepointAdapter
{
    public int $breakRoleInheritanceCalls = 0;

    public int $buildAccessUrlCalls = 0;

    public int $resolveContributorRoleCalls = 0;

    public int $resolveUserCalls = 0;

    public function __construct()
    {
        $this->settings = [
            'url' => 'https://example.test/sites/demo',
            'username' => 'demo',
            'password' => 'secret',
        ];
    }

    public function resolveContributorRoleForTest()
    {
        return $this->getCachedContributorRole();
    }

    public function resolveUserForTest(string $loginName)
    {
        return $this->getCachedUserByLoginName($loginName);
    }

    public function grantUserAccessToPath($loginName, $path)
    {
        $this->ensureUniqueRoleAssignments($path);
        $this->buildAccessUrl($loginName, $path);
    }

    protected function breakRoleInheritance($path)
    {
        $this->breakRoleInheritanceCalls++;

        return (object) ['path' => $path];
    }

    protected function buildAccessUrl($loginName, $path)
    {
        $this->buildAccessUrlCalls++;

        return 'https://example.test';
    }

    protected function resolveContributorRole()
    {
        $this->resolveContributorRoleCalls++;

        return (object) ['id' => 3];
    }

    protected function resolveUserByLoginName($loginName)
    {
        $this->resolveUserCalls++;

        return (object) ['loginName' => $loginName];
    }
}
