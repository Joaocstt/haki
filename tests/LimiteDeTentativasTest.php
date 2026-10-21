<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Tentativas\LimiteDeTentativas;
use Haki\Auth\Tentativas\PdoArmazenamentoDeTentativas;
use Haki\Exceptions\AuthenticationException;
use Haki\Exceptions\TooManyAttemptsException;
use PDO;
use PHPUnit\Framework\TestCase;

final class LimiteDeTentativasTest extends TestCase
{
    private int $agora = 1_000_000;
    private PdoArmazenamentoDeTentativas $armazenamento;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->armazenamento = new PdoArmazenamentoDeTentativas($pdo);
        $this->armazenamento->criarTabela();
        $this->armazenamento->criarTabela(); // idempotente
    }

    private function limite(int $maximo = 3, int $janela = 60): LimiteDeTentativas
    {
        return new LimiteDeTentativas($this->armazenamento, $maximo, $janela, function (): int {
            return $this->agora;
        });
    }

    private function falhar(LimiteDeTentativas $limite, array $chaves): void
    {
        try {
            $limite->verificar($chaves, static fn () => null);
            self::fail('A tentativa com senha errada deveria falhar.');
        } catch (AuthenticationException) {
        }
    }

    public function testBloqueiaDepoisDoMaximoEMesmoComSenhaCertaNaoExecuta(): void
    {
        $limite = $this->limite(3, 60);
        $chaves = ['email:ana@x.br', 'ip:1.2.3.4'];

        for ($i = 0; $i < 3; $i++) {
            $this->falhar($limite, $chaves);
        }

        $executou = false;

        try {
            $limite->verificar($chaves, function () use (&$executou) {
                $executou = true;

                return ['ok' => true];
            });
            self::fail('Deveria estar bloqueado.');
        } catch (TooManyAttemptsException $erro) {
            self::assertSame(429, $erro->getStatus());
            self::assertSame('60', $erro->getHeaders()['Retry-After']);
        }

        self::assertFalse($executou, 'Com o bloqueio, a verificação não pode nem ser executada.');
    }

    public function testSucessoZeraAsFalhas(): void
    {
        $limite = $this->limite(3, 60);
        $chaves = ['email:ana@x.br'];

        $this->falhar($limite, $chaves);
        $this->falhar($limite, $chaves);

        self::assertSame(['id' => 7], $limite->verificar($chaves, static fn () => ['id' => 7]));

        // Depois do sucesso, há de novo três tentativas disponíveis.
        $this->falhar($limite, $chaves);
        $this->falhar($limite, $chaves);
        self::assertSame(['id' => 7], $limite->verificar($chaves, static fn () => ['id' => 7]));
    }

    public function testBloqueioExpiraComAJanela(): void
    {
        $limite = $this->limite(2, 60);
        $chaves = ['ip:9.9.9.9'];

        $this->falhar($limite, $chaves);
        $this->falhar($limite, $chaves);

        $this->agora += 61;

        self::assertSame('ok', $limite->verificar($chaves, static fn () => 'ok'));
    }

    public function testExcecaoDeAutenticacaoTambemContaComoFalha(): void
    {
        $limite = $this->limite(1, 60);
        $chaves = ['email:x@x.br'];

        try {
            $limite->verificar($chaves, static function (): never {
                throw new AuthenticationException('motivo interno', 'mensagem');
            });
        } catch (AuthenticationException) {
        }

        $this->expectException(TooManyAttemptsException::class);
        $limite->verificar($chaves, static fn () => 'ok');
    }

    public function testErrosQueNaoSaoDeAutenticacaoNaoContam(): void
    {
        $limite = $this->limite(1, 60);
        $chaves = ['email:y@y.br'];

        try {
            $limite->verificar($chaves, static function (): never {
                throw new \RuntimeException('banco fora do ar');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('ok', $limite->verificar($chaves, static fn () => 'ok'));
    }

    public function testChavesSaoNormalizadas(): void
    {
        $limite = $this->limite(1, 60);

        $this->falhar($limite, ['  Email:ANA@x.br ']);

        $this->expectException(TooManyAttemptsException::class);
        $limite->verificar(['email:ana@x.br'], static fn () => 'ok');
    }
}
