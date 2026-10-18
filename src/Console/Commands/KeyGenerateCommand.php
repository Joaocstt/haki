<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Console\Output;

/**
 * Gera o JWT_SECRET no .env (HS256) ou um par de chaves RSA (RS256).
 */
final class KeyGenerateCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Gera JWT_SECRET no .env (use --rsa para gerar chaves RS256, --force para sobrescrever)';
    }

    public function run(array $args, Output $output): int
    {
        $force = in_array('--force', $args, true);

        return in_array('--rsa', $args, true) ? $this->rsa($output, $force) : $this->secret($output, $force);
    }

    private function secret(Output $output, bool $force): int
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $secret = bin2hex(random_bytes(32));

        if (preg_match('/^JWT_SECRET=.+$/m', $content) === 1 && !$force) {
            $output->error('JWT_SECRET já existe no .env.');
            $output->line('  Trocar o segredo desconecta todos os usuários. Para confirmar, rode: php vendor/bin/haki key:generate --force');

            return 1;
        }

        $line = 'JWT_SECRET=' . $secret;
        $content = preg_match('/^JWT_SECRET=.*$/m', $content) === 1
            ? (string) preg_replace('/^JWT_SECRET=.*$/m', $line, $content)
            : rtrim($content) . ($content !== '' ? PHP_EOL : '') . $line . PHP_EOL;

        file_put_contents($file, $content);

        $output->success('JWT_SECRET gerado em ' . $file);
        $output->line($output->muted('  Não versione o .env: adicione-o ao .gitignore.'));

        return 0;
    }

    private function rsa(Output $output, bool $force): int
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'keys';
        $private = $dir . DIRECTORY_SEPARATOR . 'private.pem';
        $public = $dir . DIRECTORY_SEPARATOR . 'public.pem';

        if (is_file($private) && !$force) {
            $output->error('As chaves já existem em ' . $dir . '. Use --force para gerar novas.');

            return 1;
        }

        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        // No Windows, o PHP traz o openssl.cnf em extras/ssl, mas nem sempre o encontra sozinho.
        $bundled = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';

        if (getenv('OPENSSL_CONF') === false && is_file($bundled)) {
            $options['config'] = $bundled;
        }

        $key = openssl_pkey_new($options);

        if ($key === false || !openssl_pkey_export($key, $privatePem, null, $options)) {
            $output->error('O OpenSSL não conseguiu gerar as chaves: ' . (openssl_error_string() ?: 'erro desconhecido'));
            $output->line('  No Windows, defina a variável OPENSSL_CONF apontando para o openssl.cnf do PHP, ou gere com:');
            $output->line('  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out storage/keys/private.pem');
            $output->line('  openssl rsa -in storage/keys/private.pem -pubout -out storage/keys/public.pem');

            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($private, $privatePem);
        file_put_contents($public, (string) openssl_pkey_get_details($key)['key']);
        @chmod($private, 0600);

        $output->success('Chaves RSA geradas em ' . $dir);
        $output->line('  Adicione ao .env:');
        $output->line($output->muted('    JWT_ALGORITHM=RS256'));
        $output->line($output->muted('    JWT_PRIVATE_KEY=storage/keys/private.pem'));
        $output->line($output->muted('    JWT_PUBLIC_KEY=storage/keys/public.pem'));

        return 0;
    }
}
