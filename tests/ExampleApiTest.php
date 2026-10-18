<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

/**
 * Testa a API de exemplo (tests/Fixtures/app) de ponta a ponta.
 */
final class ExampleApiTest extends TestCase
{
    private Haki $app;

    protected function setUp(): void
    {
        $this->app = require __DIR__ . '/Fixtures/app/bootstrap/app.php';
        $this->app->auth(['secret' => TestKeys::SECRET, 'storage' => new ArrayTokenStore()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function call(string $method, string $path, array $data = [], ?string $token = null): Response
    {
        $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];

        return $this->app->handle(Request::create($method, $path, $data, $headers));
    }

    public function testFullFlow(): void
    {
        self::assertSame(['api' => 'Haki', 'status' => 'online'], $this->call('GET', '/')->getJson());
        self::assertSame('http://localhost:5173', $this->call('GET', '/')->getHeader('Access-Control-Allow-Origin'));

        self::assertSame(400, $this->call('POST', '/login', ['email' => 'zoro@exemplo.com'])->getStatus());
        self::assertSame(401, $this->call('POST', '/login', ['email' => 'zoro@exemplo.com', 'senha' => 'errada'])->getStatus());

        $zoro = $this->call('POST', '/login', ['email' => 'zoro@exemplo.com', 'senha' => 'segredo123'])->getJson();
        $luffy = $this->call('POST', '/login', ['email' => 'luffy@exemplo.com', 'senha' => 'segredo123'])->getJson();

        self::assertSame(401, $this->call('GET', '/usuarios')->getStatus());
        self::assertCount(2, $this->call('GET', '/usuarios', token: $zoro['access_token'])->getJson()['usuarios']);
        self::assertSame('Roronoa Zoro', $this->call('GET', '/usuarios/2', token: $zoro['access_token'])->getJson()['usuario']['nome']);
        self::assertSame(404, $this->call('GET', '/usuarios/99', token: $zoro['access_token'])->getStatus());
        self::assertArrayNotHasKey('senhaHash', $this->call('GET', '/usuarios/2', token: $zoro['access_token'])->getJson()['usuario']);

        self::assertSame(['usuario' => ['id' => '2', 'role' => 'membro']], $this->call('GET', '/eu', token: $zoro['access_token'])->getJson());

        self::assertSame(403, $this->call('GET', '/admin/painel', token: $zoro['access_token'])->getStatus());
        self::assertSame(200, $this->call('GET', '/admin/painel', token: $luffy['access_token'])->getStatus());

        $novo = $this->call('POST', '/refresh', ['refresh_token' => $zoro['refresh_token']])->getJson();
        self::assertSame(200, $this->call('GET', '/eu', token: $novo['access_token'])->getStatus());

        self::assertSame(204, $this->call('POST', '/logout', token: $novo['access_token'])->getStatus());
        self::assertSame(401, $this->call('GET', '/eu', token: $novo['access_token'])->getStatus());
    }

    public function testCorsPreflight(): void
    {
        $response = $this->call('OPTIONS', '/usuarios');

        self::assertSame(204, $response->getStatus());
        self::assertSame('Authorization, Content-Type', $response->getHeader('Access-Control-Allow-Headers'));
    }
}
