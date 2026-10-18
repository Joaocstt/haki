<?php

declare(strict_types=1);

namespace Haki\Auth\Jwt;

use Haki\Exceptions\ConfigurationException;

/**
 * HS256: HMAC-SHA256 com segredo compartilhado.
 */
final class HmacSigner implements SignerInterface
{
    public const MIN_SECRET_BYTES = 32;

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new ConfigurationException(
                'O segredo JWT precisa ter pelo menos ' . self::MIN_SECRET_BYTES . ' caracteres.',
                'Gere um segredo forte com: php vendor/bin/haki key:generate',
            );
        }
    }

    public function algorithm(): string
    {
        return 'HS256';
    }

    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    public function verify(string $data, string $signature): bool
    {
        // hash_equals compara em tempo constante (evita timing attack).
        return hash_equals($this->sign($data), $signature);
    }
}
