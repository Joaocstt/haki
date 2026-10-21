<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\AuthConfig;
use Haki\Auth\Jwt\HmacSigner;
use Haki\Auth\Jwt\Jwt;
use Haki\Exceptions\AuthenticationException;
use Haki\Exceptions\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class RotacaoDeChavesTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const ANTIGA = 'segredo-antigo-com-mais-de-32-caracteres';
    private const NOVA = 'segredo-novo-com-mais-de-32-caracteres!';

    private function claims(): array
    {
        return ['sub' => '7', 'iat' => self::NOW, 'exp' => self::NOW + 900, 'jti' => 'abc'];
    }

    private function clock(): \Closure
    {
        return static fn (): int => self::NOW;
    }

    public function testTokenAssinadoPelaChaveAnteriorAindaValida(): void
    {
        $antiga = new Jwt(new HmacSigner(self::ANTIGA, 'k1'), null, $this->clock());
        $token = $antiga->encode($this->claims());

        $nova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock(), [new HmacSigner(self::ANTIGA, 'k1')]);

        self::assertSame('7', $nova->decode($token)['sub']);
    }

    public function testTokenNovoLevaOKidDaChaveAtual(): void
    {
        $nova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock(), [new HmacSigner(self::ANTIGA, 'k1')]);
        $token = $nova->encode($this->claims());

        $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
        self::assertSame('k2', $header['kid']);

        // Um serviço que só conhece a chave nova também valida.
        $soNova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock());
        self::assertSame('7', $soNova->decode($token)['sub']);
    }

    public function testKidDesconhecidoEhRecusado(): void
    {
        $desconhecida = new Jwt(new HmacSigner(self::ANTIGA, 'k9'), null, $this->clock());
        $token = $desconhecida->encode($this->claims());

        $nova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock(), [new HmacSigner(self::ANTIGA, 'k1')]);

        $this->expectException(AuthenticationException::class);
        $nova->decode($token);
    }

    public function testTokenSemKidAntigoAindaValida(): void
    {
        // Tokens emitidos antes da rotação não têm "kid": são conferidos com todas as chaves.
        $semKid = new Jwt(new HmacSigner(self::ANTIGA), null, $this->clock());
        $token = $semKid->encode($this->claims());

        $nova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock(), [new HmacSigner(self::ANTIGA, 'k1')]);

        self::assertSame('7', $nova->decode($token)['sub']);
    }

    public function testAssinaturaComChaveErradaComMesmoKidEhRecusada(): void
    {
        $impostor = new Jwt(new HmacSigner(str_repeat('x', 40), 'k1'), null, $this->clock());
        $token = $impostor->encode($this->claims());

        $nova = new Jwt(new HmacSigner(self::NOVA, 'k2'), null, $this->clock(), [new HmacSigner(self::ANTIGA, 'k1')]);

        $this->expectException(AuthenticationException::class);
        $nova->decode($token);
    }

    public function testRotacaoSemKidEhErroDeConfiguracao(): void
    {
        $this->expectException(ConfigurationException::class);

        AuthConfig::fromArray([
            'algorithm' => 'HS256',
            'secret' => self::NOVA,
            'previous_keys' => [['kid' => 'k1', 'secret' => self::ANTIGA]],
        ], sys_get_temp_dir());
    }

    public function testKidComCaracteresInvalidosEhErroDeConfiguracao(): void
    {
        $this->expectException(ConfigurationException::class);

        AuthConfig::fromArray(['algorithm' => 'HS256', 'secret' => self::NOVA, 'kid' => 'chave com espaço'], sys_get_temp_dir());
    }

    public function testConfigComChaveAnteriorMonta(): void
    {
        $config = AuthConfig::fromArray([
            'algorithm' => 'HS256',
            'secret' => self::NOVA,
            'kid' => 'k2',
            'previous_keys' => [['kid' => 'k1', 'secret' => self::ANTIGA]],
        ], sys_get_temp_dir());

        self::assertSame('k2', $config->signer->kid());
        self::assertCount(1, $config->chavesAnteriores);
        self::assertSame('k1', $config->chavesAnteriores[0]->kid());
    }
}
