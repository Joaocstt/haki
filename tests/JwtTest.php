<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\AuthConfig;
use Haki\Auth\Jwt\Base64Url;
use Haki\Auth\Jwt\HmacSigner;
use Haki\Auth\Jwt\Jwt;
use Haki\Exceptions\AuthenticationException;
use Haki\Exceptions\ConfigurationException;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function jwt(?string $issuer = null): Jwt
    {
        return new Jwt(new HmacSigner(TestKeys::SECRET), $issuer, static fn (): int => self::NOW);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(array $extra = []): array
    {
        return $extra + ['sub' => '1', 'iat' => self::NOW, 'exp' => self::NOW + 60, 'jti' => 'abc'];
    }

    private function assertRejected(Jwt $jwt, string $token, string $reason): void
    {
        try {
            $jwt->decode($token);
            self::fail('O token deveria ter sido rejeitado.');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->getStatus());
            self::assertSame('Token inválido ou expirado', $e->getMessage(), 'Mensagem ao cliente deve ser genérica.');
            self::assertStringContainsString($reason, (string) $e->getHint());
        }
    }

    public function testEncodesAndDecodesHs256(): void
    {
        $token = $this->jwt()->encode($this->claims(['role' => 'admin']));

        self::assertCount(3, explode('.', $token));
        self::assertStringNotContainsString('=', $token);
        self::assertSame('admin', $this->jwt()->decode($token)['role']);
    }

    public function testRejectsExpiredToken(): void
    {
        $token = $this->jwt()->encode($this->claims(['exp' => self::NOW]));

        $this->assertRejected($this->jwt(), $token, 'expirado');
    }

    public function testRejectsTamperedPayload(): void
    {
        [$header, , $signature] = explode('.', $this->jwt()->encode($this->claims()));
        $forged = Base64Url::encode((string) json_encode($this->claims(['role' => 'admin'])));

        $this->assertRejected($this->jwt(), "{$header}.{$forged}.{$signature}", 'Assinatura inválida');
    }

    public function testRejectsTokenSignedWithAnotherSecret(): void
    {
        $other = new Jwt(new HmacSigner(str_repeat('x', 40)), null, static fn (): int => self::NOW);

        $this->assertRejected($this->jwt(), $other->encode($this->claims()), 'Assinatura inválida');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'vazio' => [''],
            'duas partes' => ['abc.def'],
            'quatro partes' => ['a.b.c.d'],
            'base64 inválido' => ['@@@.###.$$$'],
            'json inválido' => [Base64Url::encode('{x') . '.' . Base64Url::encode('{}') . '.abc'],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->assertRejected($this->jwt(), $token, 'malformado');
    }

    public function testRejectsAlgNone(): void
    {
        $header = Base64Url::encode('{"alg":"none","typ":"JWT"}');
        $payload = Base64Url::encode((string) json_encode($this->claims()));

        $this->assertRejected($this->jwt(), "{$header}.{$payload}.c2ln", 'Algoritmo do token (none)');
    }

    public function testRejectsAlgorithmConfusionRs256ToHs256(): void
    {
        // Ataque clássico: assinar HS256 usando a chave PÚBLICA RSA como segredo.
        $keys = TestKeys::rsa();
        $rsConfig = AuthConfig::fromArray(['algorithm' => 'RS256', 'public_key' => $keys['public']], sys_get_temp_dir());
        $rsJwt = new Jwt($rsConfig->signer, null, static fn (): int => self::NOW);

        $forged = (new Jwt(new HmacSigner($keys['public']), null, static fn (): int => self::NOW))->encode($this->claims());

        $this->assertRejected($rsJwt, $forged, 'diferente do configurado');
    }

    public function testRejectsMissingRequiredClaims(): void
    {
        foreach (['sub', 'iat', 'exp', 'jti'] as $claim) {
            $claims = $this->claims();
            unset($claims[$claim]);

            $this->assertRejected($this->jwt(), $this->jwt()->encode($claims), $claim);
        }
    }

    public function testValidatesIssuer(): void
    {
        $token = $this->jwt('minha-api')->encode($this->claims(['iss' => 'outra-api']));

        $this->assertRejected($this->jwt('minha-api'), $token, 'iss');
    }

    public function testRs256RoundTripAndPublicOnlyCannotSign(): void
    {
        $keys = TestKeys::rsa();
        $full = AuthConfig::fromArray(['algorithm' => 'RS256', 'private_key' => $keys['private']], sys_get_temp_dir());
        $verifyOnly = AuthConfig::fromArray(['algorithm' => 'RS256', 'public_key' => $keys['public']], sys_get_temp_dir());

        $token = (new Jwt($full->signer, null, static fn (): int => self::NOW))->encode($this->claims());
        $claims = (new Jwt($verifyOnly->signer, null, static fn (): int => self::NOW))->decode($token);

        self::assertSame('1', $claims['sub']);

        $this->expectException(ConfigurationException::class);
        (new Jwt($verifyOnly->signer))->encode($this->claims());
    }

    public function testRejectsShortSecretMissingSecretAndUnknownAlgorithm(): void
    {
        $cases = [
            ['secret' => 'curto'],
            ['secret' => ''],
            [],
            ['algorithm' => 'none', 'secret' => TestKeys::SECRET],
            ['algorithm' => 'HS512', 'secret' => TestKeys::SECRET],
            ['secret' => TestKeys::SECRET, 'expires_in' => 0],
        ];

        foreach ($cases as $options) {
            try {
                AuthConfig::fromArray($options, sys_get_temp_dir());
                self::fail('Configuração deveria ser rejeitada: ' . json_encode($options));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsInvalidAndWeakRsaKeys(): void
    {
        try {
            AuthConfig::fromArray(['algorithm' => 'RS256', 'private_key' => "-----BEGIN PRIVATE KEY-----\nlixo\n-----END PRIVATE KEY-----"], sys_get_temp_dir());
            self::fail('Chave inválida deveria ser rejeitada.');
        } catch (ConfigurationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('2048 bits');
        AuthConfig::fromArray(['algorithm' => 'RS256', 'private_key' => TestKeys::rsa(1024)['private']], sys_get_temp_dir());
    }
}
