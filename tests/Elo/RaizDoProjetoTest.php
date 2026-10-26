<?php

declare(strict_types=1);

namespace Haki\Tests\Elo;

use Elo\Conexao;
use Haki\Haki;
use PHPUnit\Framework\TestCase;

/**
 * Com `php -S localhost:8000 -t public`, o diretório atual é public/. O caminho
 * relativo do SQLite no .env precisa partir da raiz passada ao Haki.
 */
final class RaizDoProjetoTest extends TestCase
{
    private string $raiz;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->raiz = sys_get_temp_dir() . '/haki-raiz-' . bin2hex(random_bytes(4));
        mkdir($this->raiz . '/public', 0777, true);
        mkdir($this->raiz . '/database');
        file_put_contents($this->raiz . '/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=database/app.sqlite\n");
        $this->limparDb();
        Conexao::limpar();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        Conexao::limpar();
        $this->limparDb();
        @unlink($this->raiz . '/database/app.sqlite');
        @rmdir($this->raiz . '/database');
        @rmdir($this->raiz . '/public');
        @unlink($this->raiz . '/.env');
        @rmdir($this->raiz);
    }

    public function testSqliteRelativoParteDaRaizDoHakiENaoDoDiretorioAtual(): void
    {
        chdir($this->raiz . '/public');

        new Haki($this->raiz);
        Conexao::executarDdl('CREATE TABLE t (id INTEGER)');

        self::assertFileExists($this->raiz . '/database/app.sqlite');
        self::assertFileDoesNotExist($this->raiz . '/public/database/app.sqlite');
    }

    private function limparDb(): void
    {
        foreach (['DB_CONNECTION', 'DB_DATABASE'] as $chave) {
            putenv($chave);
            unset($_ENV[$chave], $_SERVER[$chave]);
        }
    }
}
