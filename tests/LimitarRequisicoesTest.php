<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Tentativas\ArmazenamentoDeTentativas;
use Haki\Auth\Tentativas\PdoArmazenamentoDeTentativas;
use Haki\Haki;
use Haki\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class LimitarRequisicoesTest extends TestCase
{
    private function app(): Haki
    {
        $app = new Haki(sys_get_temp_dir());

        // Armazenamento em memória, para cada teste começar do zero.
        $app->container()->singleton(ArmazenamentoDeTentativas::class, function (): ArmazenamentoDeTentativas {
            $armazenamento = new PdoArmazenamentoDeTentativas(new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]));
            $armazenamento->criarTabela();

            return $armazenamento;
        });

        return $app;
    }

    public function testMaximoDeRequisicoesEDepoisRecusa429(): void
    {
        $app = $this->app();
        $app->post('/login', fn () => ['ok' => true])->limite(3, 900);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $app->handle(Request::create('POST', '/login'))->getStatus());
        }

        $bloqueada = $app->handle(Request::create('POST', '/login'));

        self::assertSame(429, $bloqueada->getStatus());
        self::assertSame('900', $bloqueada->getHeader('retry-after'));
    }

    public function testCadaRotaTemSeuProprioContador(): void
    {
        $app = $this->app();
        $app->post('/login', fn () => ['ok' => true])->limite(1, 900);
        $app->post('/refresh', fn () => ['ok' => true])->limite(1, 900);

        self::assertSame(200, $app->handle(Request::create('POST', '/login'))->getStatus());
        self::assertSame(429, $app->handle(Request::create('POST', '/login'))->getStatus());

        // O limite de /login não afeta /refresh.
        self::assertSame(200, $app->handle(Request::create('POST', '/refresh'))->getStatus());
    }

    public function testRotaSemLimiteNaoEhAfetada(): void
    {
        $app = $this->app();
        $app->get('/livre', fn () => ['ok' => true]);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $app->handle(Request::create('GET', '/livre'))->getStatus());
        }
    }

    public function testLimiteInvalidoEhRecusadoAoConfigurar(): void
    {
        $app = $this->app();
        $app->get('/x', fn () => ['ok' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $app->get('/y', fn () => ['ok' => true])->limite(0, 60);
    }
}
