<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Middleware\MiddlewareInterface;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class Registro
{
    /** @var list<string> */
    public static array $ordem = [];
}

final class Marcador implements MiddlewareInterface
{
    public function __construct(private readonly string $nome = 'classe')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        Registro::$ordem[] = $this->nome;

        return $next($request);
    }
}

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Registro::$ordem = [];
    }

    private function marca(string $nome): \Closure
    {
        return function (Request $request, callable $next) use ($nome) {
            Registro::$ordem[] = $nome;

            return $next($request);
        };
    }

    public function testExecutionOrderIsGlobalGroupAuthRoleRoute(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->auth(['secret' => TestKeys::SECRET, 'storage' => new ArrayTokenStore()]);
        $app->middleware($this->marca('global-1'), $this->marca('global-2'));

        $app->group('/admin', function (Haki $app) {
            $app->get('/painel', function (Request $r) {
                Registro::$ordem[] = 'controller:' . $r->user()?->role;

                return [];
            })->middleware($this->marca('rota'));
        })->middleware($this->marca('grupo'))->role('admin');

        $token = $app->container()->get(\Haki\Auth\Auth::class)->login(1, ['role' => 'admin'])['access_token'];

        $response = $app->handle(Request::create('GET', '/admin/painel', [], ['Authorization' => "Bearer {$token}"]));

        self::assertSame(200, $response->getStatus());
        self::assertSame(['global-1', 'global-2', 'grupo', 'rota', 'controller:admin'], Registro::$ordem);
    }

    public function testMiddlewareCanStopTheRequest(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->get('/x', function () {
            Registro::$ordem[] = 'controller';

            return [];
        })->middleware(fn (Request $r, callable $next) => (new Response())->json(['bloqueado' => true], 429));

        $response = $app->handle(Request::create('GET', '/x'));

        self::assertSame(429, $response->getStatus());
        self::assertSame([], Registro::$ordem);
    }

    public function testMiddlewareCanChangeTheResponseAndRunsOnErrors(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->middleware(fn (Request $r, callable $next) => $next($r)->header('X-Haki', 'sim'));
        $app->get('/x', fn () => ['ok' => true]);

        self::assertSame('sim', $app->handle(Request::create('GET', '/x'))->getHeader('X-Haki'));
        self::assertSame('sim', $app->handle(Request::create('GET', '/404'))->getHeader('X-Haki'));
    }

    public function testClassNameMiddlewareIsResolvedByContainer(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->get('/x', fn () => [])->middleware(Marcador::class, new Marcador('instancia'));

        $app->handle(Request::create('GET', '/x'));

        self::assertSame(['classe', 'instancia'], Registro::$ordem);
    }
}
