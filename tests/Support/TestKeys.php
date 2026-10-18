<?php

declare(strict_types=1);

namespace Haki\Tests\Support;

use PHPUnit\Framework\Assert;

final class TestKeys
{
    public const SECRET = 'segredo-de-teste-com-mais-de-32-caracteres';

    /** @var array{private: string, public: string}|null */
    private static ?array $rsa = null;

    /**
     * @return array{private: string, public: string}
     */
    public static function rsa(int $bits = 2048): array
    {
        if ($bits === 2048 && self::$rsa !== null) {
            return self::$rsa;
        }

        $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $bundled = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';

        if (getenv('OPENSSL_CONF') === false && is_file($bundled)) {
            $options['config'] = $bundled;
        }

        $key = openssl_pkey_new($options);

        if ($key === false || !openssl_pkey_export($key, $private, null, $options)) {
            Assert::markTestSkipped('OpenSSL não consegue gerar chaves RSA neste ambiente.');
        }

        $pair = ['private' => $private, 'public' => (string) openssl_pkey_get_details($key)['key']];

        return $bits === 2048 ? self::$rsa = $pair : $pair;
    }
}
