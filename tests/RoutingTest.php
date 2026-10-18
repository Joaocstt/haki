<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Exceptions\HakiException;
use Haki\Haki;
use Haki\Http\Request;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    private function app(bool $debug = false): Haki
    {
        return new Haki(sys_get_temp_dir(), $debug);
    }

    public function testRegistersAllHttpMethods(): void
    {
        $app = $this->app();

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $app->{$method}('/recurso', fn () => ['metodo' => $method]);
        }

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $app->handle(Request::create($method, '/recurso'));

            self::assertSame(200, $response->getStatus());
            self::assertSame(['metodo' => strtolower($method)], $response->getJson());
        }
    }

    public function testExtractsParamsInOrder(): void
    {
        $app = $this->app();
        $app->get('/usuarios/{usuario}/posts/{post}', fn (Request $r) => $r->params());

        $response = $app->handle(Request::create('GET', '/usuarios/10/posts/abc'));

        self::assertSame(['usuario' => '10', 'post' => 'abc'], $response->getJson());
    }

    public function testIgnoresTrailingSlashAndQueryString(): void
    {
        $app = $this->app();
        $app->get('/usuarios', fn (Request $r) => ['pagina' => $r->query('pagina')]);

        $response = $app->handle(Request::create('GET', '/usuarios/?pagina=2'));

        self::assertSame(['pagina' => '2'], $response->getJson());
    }

    public function testStaticRouteWinsOverDynamic(): void
    {
        $app = $this->app();
        $app->get('/usuarios/{id}', fn () => 'dinamica');
        $app->get('/usuarios/novo', fn () => 'estatica');

        self::assertSame('estatica', $app->handle(Request::create('GET', '/usuarios/novo'))->getBody());
        self::assertSame('dinamica', $app->handle(Request::create('GET', '/usuarios/5'))->getBody());
    }

    public function testUnknownPathReturns404(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/nada'));

        self::assertSame(404, $response->getStatus());
        self::assertSame(['erro' => 'Rota não encontrada', 'status' => 404], $response->getJson());
    }

    public function testDebugSuggestsSimilarRoutes(): void
    {
        $app = $this->app(debug: true);
        $app->get('/usuario/{id}', fn () => []);

        $json = $app->handle(Request::create('GET', '/usuarios/10'))->getJson();

        self::assertStringContainsString('GET /usuario/{id}', $json['detalhes']['dica']);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $app = $this->app();
        $app->get('/usuarios', fn () => []);
        $app->post('/usuarios', fn () => []);

        $response = $app->handle(Request::create('DELETE', '/usuarios'));

        self::assertSame(405, $response->getStatus());
        self::assertSame('GET, POST', $response->getHeader('Allow'));
    }

    public function testHeadFallsBackToGet(): void
    {
        $app = $this->app();
        $app->get('/status', fn () => ['ok' => true]);

        self::assertSame(200, $app->handle(Request::create('HEAD', '/status'))->getStatus());
    }

    public function testGroupsApplyPrefixIncludingNested(): void
    {
        $app = $this->app();
        $app->group('/api', function (Haki $app) {
            $app->get('/produtos', fn () => 'produtos');
            $app->group('v1', function (Haki $app) {
                $app->get('/', fn () => 'v1');
            });
        });

        self::assertSame('produtos', $app->handle(Request::create('GET', '/api/produtos'))->getBody());
        self::assertSame('v1', $app->handle(Request::create('GET', '/api/v1'))->getBody());
    }

    public function testDuplicateRouteThrows(): void
    {
        $app = $this->app();
        $app->get('/x', fn () => 1);

        $this->expectException(HakiException::class);
        $app->get('/x/', fn () => 2);
    }
}
