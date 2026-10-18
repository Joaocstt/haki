<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Exceptions\HttpException;
use Haki\Haki;
use Haki\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerTest extends TestCase
{
    private function app(bool $debug): Haki
    {
        $app = new Haki(sys_get_temp_dir(), $debug);
        $app->get('/falha', fn () => throw new RuntimeException('senha do banco: 123 em C:\\segredo.php'));
        $app->get('/http', fn () => throw new HttpException(409, 'E-mail já cadastrado'));

        return $app;
    }

    public function testProductionHidesInternalDetails(): void
    {
        $response = $this->app(false)->handle(Request::create('GET', '/falha'));

        self::assertSame(500, $response->getStatus());
        self::assertSame(['erro' => 'Erro interno do servidor', 'status' => 500], $response->getJson());
        self::assertStringNotContainsString('senha', $response->getBody());
    }

    public function testDevelopmentShowsDetails(): void
    {
        $json = $this->app(true)->handle(Request::create('GET', '/falha'))->getJson();

        self::assertSame(RuntimeException::class, $json['detalhes']['excecao']);
        self::assertArrayHasKey('trace', $json['detalhes']);
    }

    public function testHttpExceptionKeepsStatusAndMessage(): void
    {
        $response = $this->app(false)->handle(Request::create('GET', '/http'));

        self::assertSame(409, $response->getStatus());
        self::assertSame(['erro' => 'E-mail já cadastrado', 'status' => 409], $response->getJson());
    }

    public function testDefaultsToProductionWhenAppEnvIsMissing(): void
    {
        self::assertFalse((new Haki(sys_get_temp_dir()))->isDebug());
    }
}
