<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Console\Output;
use Haki\Support\Env;
use PDO;
use PDOException;

/**
 * Testa a conexão com o banco configurado nas variáveis DB_* do .env.
 *
 *     php vendor/bin/haki db
 *
 * Variáveis: DB_CONNECTION (sqlite ou mysql), DB_DATABASE, DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD.
 */
final class DbCommand implements CommandInterface
{
    private const DRIVERS = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql'];

    public function __construct(private readonly string $basePath)
    {
    }

    public function name(): string
    {
        return 'db';
    }

    public function description(): string
    {
        return 'Testa a conexão com o banco configurado no .env (DB_*)';
    }

    public function run(array $args, Output $output): int
    {
        $driver = strtolower((string) Env::get('DB_CONNECTION', ''));

        if ($driver === '') {
            return $this->fail($output, 'DB_CONNECTION não está definido no .env.', [
                'Adicione, por exemplo:',
                '  DB_CONNECTION=sqlite',
                '  DB_DATABASE=database/app.sqlite',
            ]);
        }

        if (!isset(self::DRIVERS[$driver])) {
            return $this->fail($output, "DB_CONNECTION=\"{$driver}\" não é suportado.", ['Use sqlite ou mysql.']);
        }

        $extension = self::DRIVERS[$driver];

        if (!extension_loaded($extension)) {
            return $this->fail($output, "A extensão {$extension} não está ativa no PHP.", [
                "Ative a linha \"extension={$extension}\" no php.ini (veja o caminho com: php --ini) e abra o terminal de novo.",
            ]);
        }

        $started = microtime(true);

        try {
            [$pdo, $target] = $driver === 'sqlite' ? $this->sqlite() : $this->mysql();
        } catch (PDOException $e) {
            return $this->fail($output, 'Não foi possível conectar: ' . $e->getMessage(), $this->hints($driver, $e));
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage(), []);
        }

        $ms = round((microtime(true) - $started) * 1000, 1);
        [$version, $tables] = $this->inspect($pdo, $driver);

        $output->success('Conectado ao banco.');
        $output->line('  Driver:   ' . $driver);
        $output->line('  Banco:    ' . $target);
        $output->line('  Versão:   ' . $version);
        $output->line('  Tabelas:  ' . ($tables === [] ? $output->muted('nenhuma ainda') : implode(', ', $tables)));
        $output->line('  Tempo:    ' . $ms . ' ms');

        return 0;
    }

    /**
     * @return array{PDO, string}
     */
    private function sqlite(): array
    {
        $database = (string) Env::get('DB_DATABASE', '');

        if ($database === '') {
            throw new \RuntimeException('DB_DATABASE não está definido no .env (o caminho do arquivo SQLite, ex.: database/app.sqlite).');
        }

        if ($database !== ':memory:' && preg_match('#^([A-Za-z]:[\\\\/]|/)#', $database) !== 1) {
            $database = rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . $database;
        }

        // O PDO criaria um arquivo vazio sem avisar; aqui é melhor dizer que ele não existe.
        if ($database !== ':memory:' && !is_file($database)) {
            throw new \RuntimeException(
                "O arquivo do banco não existe: {$database}. Crie as tabelas primeiro, ou confira o caminho em DB_DATABASE.",
            );
        }

        return [new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), $database];
    }

    /**
     * @return array{PDO, string}
     */
    private function mysql(): array
    {
        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (int) Env::get('DB_PORT', 3306);
        $database = (string) Env::get('DB_DATABASE', '');
        $username = Env::get('DB_USERNAME');

        if ($database === '') {
            throw new \RuntimeException('DB_DATABASE não está definido no .env (o nome do banco, ex.: loja).');
        }

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            is_string($username) ? $username : null,
            Env::get('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );

        return [$pdo, (is_string($username) ? $username . '@' : '') . "{$host}:{$port}/{$database}"];
    }

    /**
     * @return array{string, list<string>}
     */
    private function inspect(PDO $pdo, string $driver): array
    {
        if ($driver === 'sqlite') {
            $version = 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn();
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                ->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $version = 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn();
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        }

        return [(string) $version, array_map('strval', $tables)];
    }

    /**
     * Dicas para os erros mais comuns do MySQL.
     *
     * @return list<string>
     */
    private function hints(string $driver, PDOException $e): array
    {
        if ($driver !== 'mysql') {
            return ['Confira o caminho em DB_DATABASE e a permissão de leitura do arquivo.'];
        }

        $code = (int) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            1045 => ['Usuário ou senha incorretos. Confira DB_USERNAME e DB_PASSWORD.'],
            1049 => ['O banco não existe. Crie-o (CREATE DATABASE nome;) ou corrija DB_DATABASE.'],
            2002 => ['O servidor MySQL não respondeu. Confira se ele está ligado e se DB_HOST e DB_PORT estão certos.'],
            2005 => ['Endereço do servidor desconhecido. Confira DB_HOST.'],
            default => ['Confira as variáveis DB_* no .env.'],
        };
    }

    /**
     * @param list<string> $hints
     */
    private function fail(Output $output, string $message, array $hints): int
    {
        $output->error($message);

        foreach ($hints as $hint) {
            $output->line('  ' . $hint);
        }

        return 1;
    }
}
