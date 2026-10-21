<?php

declare(strict_types=1);

namespace Haki\Auth;

use Haki\Auth\Jwt\HmacSigner;
use Haki\Auth\Jwt\RsaSigner;
use Haki\Auth\Jwt\SignerInterface;
use Haki\Auth\Storage\TokenStore;
use Haki\Exceptions\ConfigurationException;
use Haki\Support\Env;
use OpenSSLAsymmetricKey;

/**
 * Configuração validada da autenticação. Nunca usa valores inseguros como padrão.
 */
final class AuthConfig
{
    public const ALGORITHMS = ['HS256', 'RS256'];
    public const DEFAULT_EXPIRES_IN = 900;     // 15 minutos
    public const DEFAULT_REFRESH_IN = 604800;  // 7 dias

    private function __construct(
        public readonly SignerInterface $signer,
        public readonly int $expiresIn,
        public readonly int $refreshIn,
        public readonly ?string $issuer,
        public readonly string $database,
        public readonly ?TokenStore $storage,
        public readonly array $chavesAnteriores = [],
    ) {
    }

    /**
     * @param array{
     *     algorithm?: string, secret?: string|null, private_key?: string|null, public_key?: string|null,
     *     kid?: string|null, previous_keys?: list<array{kid: string, secret?: string|null, public_key?: string|null}>,
     *     expires_in?: int, refresh_in?: int, issuer?: string|null, database?: string, storage?: TokenStore|null
     * } $options
     */
    public static function fromArray(array $options, string $basePath): self
    {
        $algorithm = strtoupper((string) ($options['algorithm'] ?? 'HS256'));

        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new ConfigurationException(
                "Algoritmo JWT não suportado: {$algorithm}.",
                'Use HS256 (segredo compartilhado) ou RS256 (par de chaves RSA).',
            );
        }

        $kid = isset($options['kid']) && $options['kid'] !== '' ? self::kid((string) $options['kid']) : null;
        $previous = $options['previous_keys'] ?? [];

        if (!is_array($previous)) {
            throw new ConfigurationException("A opção 'previous_keys' deve ser uma lista.");
        }

        if ($previous !== [] && $kid === null) {
            throw new ConfigurationException(
                "Para trocar a chave JWT, a chave atual precisa de um identificador (kid).",
                'Defina JWT_KID no .env, por exemplo JWT_KID=k2.',
            );
        }

        $signer = $algorithm === 'HS256'
            ? self::hmac($options['secret'] ?? null, $kid)
            : self::rsa($options['private_key'] ?? null, $options['public_key'] ?? null, $basePath, $kid);

        $anteriores = [];
        $kids = $kid === null ? [] : [$kid];

        foreach ($previous as $item) {
            if (!is_array($item) || !isset($item['kid'])) {
                throw new ConfigurationException("Cada chave anterior precisa de um 'kid'.");
            }

            $previousKid = self::kid((string) $item['kid']);

            if (in_array($previousKid, $kids, true)) {
                throw new ConfigurationException("O kid \"{$previousKid}\" aparece mais de uma vez na configuração de chaves JWT.");
            }

            $kids[] = $previousKid;
            $anteriores[] = $algorithm === 'HS256'
                ? self::hmac($item['secret'] ?? null, $previousKid)
                : self::rsaPublica($item['public_key'] ?? null, $basePath, $previousKid);
        }

        $storage = $options['storage'] ?? null;

        if ($storage !== null && !$storage instanceof TokenStore) {
            throw new ConfigurationException("A opção 'storage' deve implementar " . TokenStore::class . '.');
        }

        return new self(
            $signer,
            self::positive($options['expires_in'] ?? self::DEFAULT_EXPIRES_IN, 'expires_in'),
            self::positive($options['refresh_in'] ?? self::DEFAULT_REFRESH_IN, 'refresh_in'),
            isset($options['issuer']) && $options['issuer'] !== '' ? (string) $options['issuer'] : null,
            self::absolute((string) ($options['database'] ?? 'storage/haki.sqlite'), $basePath),
            $storage,
            $anteriores,
        );
    }

    /**
     * Lê JWT_SECRET, JWT_ALGORITHM, JWT_PRIVATE_KEY, JWT_PUBLIC_KEY, JWT_TTL, JWT_REFRESH_TTL,
     * JWT_ISSUER e JWT_DATABASE do ambiente.
     */
    public static function fromEnv(string $basePath): self
    {
        return self::fromArray(array_filter([
            'algorithm' => Env::get('JWT_ALGORITHM'),
            'secret' => Env::get('JWT_SECRET'),
            'private_key' => Env::get('JWT_PRIVATE_KEY'),
            'public_key' => Env::get('JWT_PUBLIC_KEY'),
            'expires_in' => self::intOrNull(Env::get('JWT_TTL')),
            'refresh_in' => self::intOrNull(Env::get('JWT_REFRESH_TTL')),
            'issuer' => Env::get('JWT_ISSUER'),
            'database' => Env::get('JWT_DATABASE'),
            'kid' => Env::get('JWT_KID'),
            'previous_keys' => self::previousKeysFromEnv(),
        ], static fn (mixed $v): bool => $v !== null), $basePath);
    }

    /**
     * Uma chave anterior, para a janela de rotação: JWT_KID_ANTERIOR com JWT_SECRET_ANTERIOR
     * (HS256) ou JWT_PUBLIC_KEY_ANTERIOR (RS256).
     *
     * @return list<array{kid: string, secret: mixed, public_key: mixed}>
     */
    private static function previousKeysFromEnv(): array
    {
        $kid = Env::get('JWT_KID_ANTERIOR');

        if ($kid === null) {
            return [];
        }

        return [[
            'kid' => (string) $kid,
            'secret' => Env::get('JWT_SECRET_ANTERIOR'),
            'public_key' => Env::get('JWT_PUBLIC_KEY_ANTERIOR'),
        ]];
    }

    private static function kid(string $kid): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $kid) !== 1) {
            throw new ConfigurationException(
                "O kid \"{$kid}\" é inválido. Use de 1 a 32 letras, números, _ ou -.",
                'Exemplos: k1, k2, 2026-01.',
            );
        }

        return $kid;
    }

    private static function hmac(mixed $secret, ?string $kid = null): HmacSigner
    {
        if (!is_string($secret) || $secret === '') {
            throw new ConfigurationException(
                'O segredo JWT não foi definido.',
                'Rode "php vendor/bin/haki key:generate" para criar JWT_SECRET no .env, '
                . "ou passe 'secret' em \$app->auth([...]).",
            );
        }

        return new HmacSigner($secret, $kid);
    }

    private static function rsaPublica(mixed $public, string $basePath, string $kid): RsaSigner
    {
        if (!is_string($public) || $public === '') {
            throw new ConfigurationException("A chave pública anterior (kid \"{$kid}\") não foi definida.");
        }

        $key = openssl_pkey_get_public(self::pem($public, $basePath, 'public_key'));

        if ($key === false) {
            throw new ConfigurationException("A chave pública anterior (kid \"{$kid}\") é inválida.");
        }

        self::assertRsa($key, 'pública anterior');

        return new RsaSigner($key, null, $kid);
    }

    private static function rsa(mixed $private, mixed $public, string $basePath, ?string $kid = null): RsaSigner
    {
        $privateKey = null;

        if (is_string($private) && $private !== '') {
            $privateKey = openssl_pkey_get_private(self::pem($private, $basePath, 'private_key'));

            if ($privateKey === false) {
                throw new ConfigurationException('A chave privada RSA é inválida.', 'Gere um novo par com: php vendor/bin/haki key:generate --rsa');
            }

            self::assertRsa($privateKey, 'privada');
        }

        if (is_string($public) && $public !== '') {
            $publicKey = openssl_pkey_get_public(self::pem($public, $basePath, 'public_key'));
        } elseif ($privateKey !== null) {
            // A chave pública pode ser derivada da privada.
            $publicKey = openssl_pkey_get_public((string) openssl_pkey_get_details($privateKey)['key']);
        } else {
            throw new ConfigurationException(
                'RS256 exige ao menos a chave pública (para validar) e a privada (para emitir tokens).',
                "Informe 'private_key' e 'public_key' em \$app->auth([...]) ou JWT_PRIVATE_KEY/JWT_PUBLIC_KEY no .env.",
            );
        }

        if ($publicKey === false) {
            throw new ConfigurationException('A chave pública RSA é inválida.');
        }

        self::assertRsa($publicKey, 'pública');

        return new RsaSigner($publicKey, $privateKey, $kid);
    }

    private static function assertRsa(OpenSSLAsymmetricKey $key, string $label): void
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new ConfigurationException("A chave {$label} não é uma chave RSA.");
        }

        if ($details['bits'] < 2048) {
            throw new ConfigurationException("A chave {$label} RSA precisa ter pelo menos 2048 bits (tem {$details['bits']}).");
        }
    }

    /**
     * Aceita o conteúdo PEM ou o caminho de um arquivo .pem.
     */
    private static function pem(string $value, string $basePath, string $option): string
    {
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        $file = self::absolute($value, $basePath);

        if (!is_file($file)) {
            throw new ConfigurationException("Arquivo de chave não encontrado para '{$option}': {$file}.");
        }

        return (string) file_get_contents($file);
    }

    private static function positive(mixed $value, string $option): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new ConfigurationException("A opção '{$option}' deve ser um número inteiro de segundos maior que zero.");
        }

        return $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private static function absolute(string $path, string $basePath): string
    {
        if ($path === ':memory:' || preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\)#', $path) === 1) {
            return $path;
        }

        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
