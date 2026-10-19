<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Console\Commands\DbCommand;
use Haki\Console\Output;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class DbCommandTest extends TestCase
{
    private const KEYS = ['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-db-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/database', 0777, true);
        $this->env([]);
    }

    protected function tearDown(): void
    {
        $this->env([]);
        @unlink($this->dir . '/database/app.sqlite');
        @rmdir($this->dir . '/database');
        @rmdir($this->dir);
    }

    /**
     * @param array<string, string> $values
     */
    private function env(array $values): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
        }
    }

    /**
     * @return array{int, string}
     */
    private function run_(): array
    {
        $stream = fopen('php://memory', 'w+');
        $code = (new DbCommand($this->dir))->run([], new Output($stream, false));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    }

    public function testMissingConfigurationExplainsWhatToAdd(): void
    {
        [$code, $output] = $this->run_();

        self::assertSame(1, $code);
        self::assertStringContainsString('DB_CONNECTION não está definido', $output);
        self::assertStringContainsString('DB_CONNECTION=sqlite', $output);
    }

    public function testUnsupportedDriver(): void
    {
        $this->env(['DB_CONNECTION' => 'oracle']);

        [$code, $output] = $this->run_();

        self::assertSame(1, $code);
        self::assertStringContainsString('não é suportado', $output);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSqliteFileThatDoesNotExistIsReportedAndNotCreated(): void
    {
        $this->env(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'database/app.sqlite']);

        [$code, $output] = $this->run_();

        self::assertSame(1, $code);
        self::assertStringContainsString('O arquivo do banco não existe', $output);
        self::assertFileDoesNotExist($this->dir . '/database/app.sqlite');
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSuccessfulSqliteConnectionShowsDetails(): void
    {
        $pdo = new PDO('sqlite:' . $this->dir . '/database/app.sqlite');
        $pdo->exec('CREATE TABLE clientes (id INTEGER PRIMARY KEY); CREATE TABLE pedidos (id INTEGER PRIMARY KEY);');
        $pdo = null;

        $this->env(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'database/app.sqlite']);

        [$code, $output] = $this->run_();

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('✔ Conectado ao banco.', $output);
        self::assertStringContainsString('Driver:   sqlite', $output);
        self::assertStringContainsString('Tabelas:  clientes, pedidos', $output);
        self::assertMatchesRegularExpression('/Versão:   SQLite \d+\.\d+/', $output);
    }

    public function testMysqlWithoutExtensionOrServerGivesAHint(): void
    {
        $this->env([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',          // porta fechada: nunca há servidor aqui
            'DB_DATABASE' => 'loja',
            'DB_USERNAME' => 'root',
        ]);

        [$code, $output] = $this->run_();

        self::assertSame(1, $code);

        if (!extension_loaded('pdo_mysql')) {
            self::assertStringContainsString('extension=pdo_mysql', $output);
        } else {
            self::assertStringContainsString('Não foi possível conectar', $output);
        }
    }
}
