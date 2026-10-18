<?php

declare(strict_types=1);

namespace Haki\Auth\Jwt;

/**
 * Base64 seguro para URL (RFC 4648 §5), sem preenchimento "=".
 */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Retorna null se o texto não for base64url válido.
     */
    public static function decode(string $data): ?string
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
