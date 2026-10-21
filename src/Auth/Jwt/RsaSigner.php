<?php

declare(strict_types=1);

namespace Haki\Auth\Jwt;

use Haki\Exceptions\ConfigurationException;
use OpenSSLAsymmetricKey;

/**
 * RS256: assina com a chave privada e valida com a pública.
 *
 * Um serviço que só valida tokens precisa apenas da chave pública.
 */
final class RsaSigner implements SignerInterface
{
    public function __construct(
        private readonly OpenSSLAsymmetricKey $publicKey,
        private readonly ?OpenSSLAsymmetricKey $privateKey = null,
        private readonly ?string $kid = null,
    ) {
    }

    public function kid(): ?string
    {
        return $this->kid;
    }

    public function algorithm(): string
    {
        return 'RS256';
    }

    public function sign(string $data): string
    {
        if ($this->privateKey === null) {
            throw new ConfigurationException(
                'Não é possível emitir tokens RS256 sem a chave privada.',
                "Informe 'private_key' em \$app->auth([...]) ou JWT_PRIVATE_KEY no .env.",
            );
        }

        if (!openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new ConfigurationException('Falha ao assinar o token com a chave privada RSA.');
        }

        return $signature;
    }

    public function verify(string $data, string $signature): bool
    {
        return openssl_verify($data, $signature, $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
