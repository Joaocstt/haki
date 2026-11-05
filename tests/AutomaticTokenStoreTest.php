<?php

declare(strict_types=1);

namespace Haki\Tests;

use Elo\Conexao;
use Haki\Auth\Auth;
use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Auth\Storage\PdoTokenStore;
use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Auth\Storage\TokenStore;
use Haki\Console\Application;
use Haki\Console\Output;
use Haki\Haki;
use Haki\Tests\Support\TestKeys;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutomaticTokenStoreTest extends TestCase
{
    private mixed $previous;

    protected function setUp(): void
    {
        $this->previous = $_ENV['DB_CONNECTION'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['DB_CONNECTION']);
        } else {
            $_ENV['DB_CONNECTION'] = $this->previous;
        }
    }

    public function testMysqlAutomaticallyCreatesTablesAndSupportsAuthAndCli(): void
    {
        $_ENV['DB_CONNECTION'] = 'mysql';
        // PDO em memória exercita a integração sem exigir servidor MySQL no teste.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        Conexao::configurar($pdo);
        $app = new Haki(sys_get_temp_dir());
        $app->auth(['secret' => TestKeys::SECRET]);
        self::assertInstanceOf(PdoTokenStore::class, $app->container()->get(TokenStore::class));
        $auth = $app->container()->get(Auth::class);
        $tokens = $auth->login(7);
        self::assertSame('7', $auth->authenticate($tokens['access_token'])->id);
        $renewed = $auth->refresh($tokens['refresh_token']);
        self::assertSame('7', $auth->authenticate($renewed['access_token'])->id);

        $cli = new Application(sys_get_temp_dir(), new Output(fopen('php://memory', 'w+'), false));
        self::assertSame(0, $cli->run(['auth:install']));
        self::assertSame(0, $cli->run(['auth:clean']));
    }

    public function testSqliteRemainsDefaultAndExplicitStoreTakesPrecedence(): void
    {
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $app = new Haki(sys_get_temp_dir());
        $app->auth(['secret' => TestKeys::SECRET]);
        self::assertInstanceOf(SqliteTokenStore::class, $app->container()->get(TokenStore::class));

        $_ENV['DB_CONNECTION'] = 'mysql';
        $custom = new ArrayTokenStore();
        $app = new Haki(sys_get_temp_dir());
        $app->auth(['secret' => TestKeys::SECRET, 'storage' => $custom]);
        self::assertSame($custom, $app->container()->get(TokenStore::class));
    }
}
