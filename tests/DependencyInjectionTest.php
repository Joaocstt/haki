<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Auth;
use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Container\Container;
use Haki\Exceptions\ContainerException;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

interface Saudacao
{
    public function ola(string $nome): string;
}

final class SaudacaoFormal implements Saudacao
{
    public function ola(string $nome): string
    {
        return "Saudações, {$nome}";
    }
}

final class SaudacaoController
{
    public function __construct(private readonly Saudacao $saudacao)
    {
    }

    public function show(Response $response, string $nome, Request $request, Auth $auth): Response
    {
        return $response->json([
            'texto' => $this->saudacao->ola($nome),
            'mesmaRequest' => $request->param('nome') === $nome,
            'auth' => $auth instanceof Auth,
        ]);
    }

    public function numero(int $id, ?string $extra = 'padrao'): array
    {
        return ['id' => $id, 'extra' => $extra];
    }

    public function quebrado(string $naoExiste): array
    {
        return [];
    }
}

final class CicloA
{
    public function __construct(public CicloB $b)
    {
    }
}

final class CicloB
{
    public function __construct(public CicloA $a)
    {
    }
}

final class DependencyInjectionTest extends TestCase
{
    private function app(): Haki
    {
        $app = new Haki(sys_get_temp_dir(), debug: true);
        $app->auth(['secret' => TestKeys::SECRET, 'storage' => new ArrayTokenStore()]);
        $app->container()->bind(Saudacao::class, SaudacaoFormal::class);

        return $app;
    }

    public function testInjectsRequestResponseAuthAndParamsInAnyOrder(): void
    {
        $app = $this->app();
        $app->get('/ola/{nome}', [SaudacaoController::class, 'show']);

        $json = $app->handle(Request::create('GET', '/ola/Robin'))->getJson();

        self::assertSame(['texto' => 'Saudações, Robin', 'mesmaRequest' => true, 'auth' => true], $json);
    }

    public function testCastsTypedRouteParamsAndUsesDefaults(): void
    {
        $app = $this->app();
        $app->get('/n/{id}', [SaudacaoController::class, 'numero']);

        self::assertSame(['id' => 42, 'extra' => 'padrao'], $app->handle(Request::create('GET', '/n/42'))->getJson());
        self::assertSame(404, $app->handle(Request::create('GET', '/n/abc'))->getStatus());
    }

    public function testClosureHandlersAreInjectedToo(): void
    {
        $app = $this->app();
        $app->get('/soma/{a}/{b}', fn (int $a, int $b) => ['soma' => $a + $b]);

        self::assertSame(['soma' => 5], $app->handle(Request::create('GET', '/soma/2/3'))->getJson());
    }

    public function testUnresolvableParameterGivesClearError(): void
    {
        $app = $this->app();
        $app->get('/q', [SaudacaoController::class, 'quebrado']);

        $json = $app->handle(Request::create('GET', '/q'))->getJson();

        self::assertSame(500, $json['status']);
        self::assertStringContainsString('$naoExiste', $json['detalhes']['mensagem']);
        self::assertArrayHasKey('dica', $json['detalhes']);
    }

    public function testInterfaceWithoutBindingExplainsHowToFix(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('é uma interface');

        (new Container())->get(Saudacao::class);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(SaudacaoFormal::class);

        self::assertSame($container->get(SaudacaoFormal::class), $container->get(SaudacaoFormal::class));
        self::assertNotSame((new Container())->get(SaudacaoFormal::class), (new Container())->get(SaudacaoFormal::class));
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('circular');

        (new Container())->get(CicloA::class);
    }
}
