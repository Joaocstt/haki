<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Storage\RefreshTokenRecord;
use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteTokenStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }

        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    private function record(string $hash, int $expiresAt = 2000): RefreshTokenRecord
    {
        return new RefreshTokenRecord($hash, 'u1', 'fam', ['role' => 'admin'], 1000, $expiresAt);
    }

    public function testCreatesDatabaseAutomaticallyAndIsIdempotent(): void
    {
        $path = $this->dir . '/storage/haki.sqlite';

        (new SqliteTokenStore($path))->install();
        (new SqliteTokenStore($path))->install();

        self::assertFileExists($path);
    }

    public function testRefreshTokenLifecycle(): void
    {
        $store = new SqliteTokenStore($this->dir . '/t.sqlite');
        $store->saveRefreshToken($this->record('h1'));

        $found = $store->findRefreshToken('h1');
        self::assertNotNull($found);
        self::assertSame(['role' => 'admin'], $found->claims);
        self::assertNull($found->usedAt);

        self::assertTrue($store->consumeRefreshToken('h1', 1500));
        self::assertFalse($store->consumeRefreshToken('h1', 1501), 'Consumo é atômico: segunda vez falha.');
        self::assertSame(1500, $store->findRefreshToken('h1')?->usedAt);

        $store->saveRefreshToken($this->record('h2'));
        $store->revokeFamily('fam', 1600);
        self::assertSame(1600, $store->findRefreshToken('h2')?->revokedAt);
        self::assertFalse($store->consumeRefreshToken('h2', 1700));
    }

    public function testRevocationsAndPurge(): void
    {
        $store = new SqliteTokenStore($this->dir . '/t.sqlite');

        $store->revokeAccessToken('jti-1', 100);
        $store->revokeAccessToken('jti-2', 5000);
        self::assertTrue($store->isAccessTokenRevoked('jti-1'));
        self::assertFalse($store->isAccessTokenRevoked('outro'));

        $store->revokeUser('u1', 300);
        $store->revokeUser('u1', 200);
        self::assertSame(300, $store->userRevokedBefore('u1'), 'Nunca volta no tempo.');
        self::assertNull($store->userRevokedBefore('u2'));

        $store->saveRefreshToken($this->record('velho', 100));
        self::assertSame(2, $store->purgeExpired(1000));
        self::assertFalse($store->isAccessTokenRevoked('jti-1'));
        self::assertTrue($store->isAccessTokenRevoked('jti-2'));
    }

    public function testHakiUsesSqliteByDefault(): void
    {
        $app = new Haki($this->dir);
        $app->auth(['secret' => TestKeys::SECRET]);
        $app->post('/login', fn (\Haki\Auth\Auth $auth) => $auth->login(1));
        $app->get('/eu', fn (Request $r) => $r->user())->auth();

        $tokens = $app->handle(Request::create('POST', '/login'))->getJson();
        $response = $app->handle(Request::create('GET', '/eu', [], ['Authorization' => 'Bearer ' . $tokens['access_token']]));

        self::assertSame(200, $response->getStatus());
        self::assertFileExists($this->dir . '/storage/haki.sqlite');
    }
}
