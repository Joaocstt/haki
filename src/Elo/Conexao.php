<?php

declare(strict_types=1);

namespace Elo;

use Elo\Excecoes\EloException;
use Elo\Gramaticas\ContratoGramatica;
use Elo\Gramaticas\GramaticaMySql;
use Elo\Gramaticas\GramaticaPostgres;
use Elo\Gramaticas\GramaticaSqlite;
use Elo\Gramaticas\GramaticaSqlServer;
use Haki\Support\Env;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Conexão única do EloPHP, com o PDO e a gramática do banco em uso.
 *
 * Duas formas de configurar:
 *
 *     // 1) Pelo .env (DB_CONNECTION, DB_DATABASE...), na primeira vez que o banco for usado
 *     // 2) Explicitamente, no bootstrap:
 *     Conexao::configurar(['driver' => 'sqlite', 'arquivo' => __DIR__ . '/../database/app.sqlite']);
 *     Conexao::configurar(new PDO('sqlite::memory:'));
 */
final class Conexao
{
    private static ?PDO $pdo = null;
    private static ?ContratoGramatica $gramatica = null;
    private static ?string $raiz = null;

    /**
     * @param PDO|array{driver: string, arquivo?: string, host?: string, porta?: int|string, banco?: string, usuario?: string|null, senha?: string|null} $origem
     */
    public static function configurar(PDO|array $origem): void
    {
        self::$gramatica = null;

        if ($origem instanceof PDO) {
            self::$pdo = $origem;
            return;
        }

        self::$pdo = self::criarPdo($origem);
    }

    /**
     * Pasta usada para resolver caminhos relativos de SQLite (DB_DATABASE=database/app.sqlite).
     * Padrão: a pasta de onde o PHP foi iniciado.
     */
    public static function raizDoProjeto(string $caminho): void
    {
        self::$raiz = rtrim($caminho, '/\\');
    }

    public static function obter(): PDO
    {
        if (self::$pdo === null) {
            self::configurar(self::configuracaoDoAmbiente());
        }

        return self::$pdo;
    }

    public static function gramatica(): ContratoGramatica
    {
        if (self::$gramatica === null) {
            self::$gramatica = match (self::obter()->getAttribute(PDO::ATTR_DRIVER_NAME)) {
                'mysql' => new GramaticaMySql(),
                'pgsql' => new GramaticaPostgres(),
                'sqlsrv' => new GramaticaSqlServer(),
                'sqlite' => new GramaticaSqlite(),
                default => throw new EloException('Driver de banco não suportado pelo EloPHP. Use mysql, pgsql, sqlsrv ou sqlite.'),
            };
        }

        return self::$gramatica;
    }

    /**
     * Executa um SQL com ? como parâmetros. Sempre usa prepared statements.
     *
     * @param list<mixed> $valores
     */
    public static function executar(string $sql, array $valores = []): PDOStatement
    {
        // PDO envia false como string vazia; 0 e 1 são o formato correto para booleanos.
        $valores = array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, $valores);

        $stmt = self::obter()->prepare($sql);
        $stmt->execute($valores);

        return $stmt;
    }

    /** Comandos de estrutura (CREATE, ALTER, DROP), sem parâmetros. */
    public static function executarDdl(string $sql): void
    {
        self::obter()->exec($sql);
    }

    /**
     * Roda a operação dentro de uma transação: tudo ou nada.
     *
     * Se a operação lançar erro, a transação é desfeita e o erro é relançado.
     * Dentro de outra transação, a chamada participa da transação que já está aberta.
     */
    public static function transacao(callable $operacao): mixed
    {
        $pdo = self::obter();

        if ($pdo->inTransaction()) {
            return $operacao();
        }

        $pdo->beginTransaction();

        try {
            $resultado = $operacao();
            $pdo->commit();

            return $resultado;
        } catch (Throwable $erro) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $erro;
        }
    }

    /** Esquece a conexão atual (útil em testes). */
    public static function limpar(): void
    {
        self::$pdo = null;
        self::$gramatica = null;
    }

    /**
     * @return array{driver: string, arquivo?: string, host?: string, porta?: string, banco?: string, usuario?: string|null, senha?: string|null}
     */
    private static function configuracaoDoAmbiente(): array
    {
        $conexao = strtolower((string) Env::get('DB_CONNECTION', ''));

        $driver = match ($conexao) {
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlsrv', 'mssql' => 'sqlsrv',
            '' => throw new EloException('Nenhum banco configurado. Defina DB_CONNECTION no .env ou chame Conexao::configurar().'),
            default => throw new EloException("DB_CONNECTION=\"{$conexao}\" não é suportado pelo EloPHP. Use sqlite, mysql, pgsql ou sqlsrv."),
        };

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'arquivo' => (string) Env::get('DB_DATABASE', '')];
        }

        return [
            'driver' => $driver,
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'porta' => (string) Env::get('DB_PORT', ''),
            'banco' => (string) Env::get('DB_DATABASE', ''),
            'usuario' => Env::get('DB_USERNAME') === null ? null : (string) Env::get('DB_USERNAME'),
            'senha' => Env::get('DB_PASSWORD') === null ? null : (string) Env::get('DB_PASSWORD'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function criarPdo(array $config): PDO
    {
        $driver = (string) ($config['driver'] ?? '');

        $opcoes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $arquivo = (string) ($config['arquivo'] ?? '');

            if ($arquivo === '') {
                throw new EloException('Para SQLite, informe o caminho do arquivo em "arquivo" (ou use :memory:).');
            }

            if ($arquivo !== ':memory:' && preg_match('#^([A-Za-z]:[\\\\/]|/)#', $arquivo) !== 1) {
                $arquivo = (self::$raiz ?? (string) getcwd()) . DIRECTORY_SEPARATOR . $arquivo;
            }

            $pdo = new PDO('sqlite:' . $arquivo, null, null, $opcoes);
            // Sem isso, o SQLite ignora as chaves estrangeiras (e o ON DELETE CASCADE).
            $pdo->exec('PRAGMA foreign_keys = ON');

            return $pdo;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $banco = (string) ($config['banco'] ?? '');
        $usuario = isset($config['usuario']) ? (string) $config['usuario'] : null;
        $senha = isset($config['senha']) ? (string) $config['senha'] : null;

        if ($banco === '') {
            throw new EloException('Informe o nome do banco (DB_DATABASE ou "banco" na configuração).');
        }

        return match ($driver) {
            'mysql' => new PDO(
                'mysql:host=' . $host . ';port=' . self::porta($config, '3306') . ";dbname={$banco};charset=utf8mb4",
                $usuario,
                $senha,
                $opcoes,
            ),
            'pgsql' => new PDO(
                'pgsql:host=' . $host . ';port=' . self::porta($config, '5432') . ";dbname={$banco}",
                $usuario,
                $senha,
                $opcoes,
            ),
            'sqlsrv' => new PDO(
                'sqlsrv:Server=' . $host . ',' . self::porta($config, '1433') . ";Database={$banco}",
                $usuario,
                $senha,
                $opcoes,
            ),
            default => throw new EloException("Driver \"{$driver}\" não suportado pelo EloPHP."),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function porta(array $config, string $padrao): string
    {
        $porta = (string) ($config['porta'] ?? '');

        return $porta === '' ? $padrao : $porta;
    }
}
