<?php

declare(strict_types=1);

namespace Haki\Tests\Elo;

use Elo\Conexao;
use Elo\Console\ExecutorDeMigracoes;
use Elo\Tabela;
use PHPUnit\Framework\TestCase;

final class MigracoesTest extends TestCase
{
    private string $pasta;

    protected function setUp(): void
    {
        Conexao::limpar();
        Conexao::configurar(['driver' => 'sqlite', 'arquivo' => ':memory:']);

        $this->pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'elo_mig_' . uniqid();
        mkdir($this->pasta);

        file_put_contents($this->pasta . '/2026_01_01_000001_criar_setores.php', <<<'PHP'
<?php
use Elo\Coluna;
use Elo\Migracao;
use Elo\Tabela;
return new class extends Migracao {
    public function subir(): void { Tabela::criar('setores', ['nome' => Coluna::texto(100)]); }
    public function descer(): void { Tabela::apagar('setores'); }
};
PHP);

        file_put_contents($this->pasta . '/2026_01_01_000002_criar_cargos.php', <<<'PHP'
<?php
use Elo\Coluna;
use Elo\Migracao;
use Elo\Tabela;
return new class extends Migracao {
    public function subir(): void { Tabela::criar('cargos', ['nome' => Coluna::texto(50)]); }
    public function descer(): void { Tabela::apagar('cargos'); }
};
PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta . '/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }

        rmdir($this->pasta);
        Conexao::limpar();
    }

    public function testMigrarAplicaEmLoteEReverterDesfazOUltimoLote(): void
    {
        $executor = new ExecutorDeMigracoes($this->pasta);

        self::assertSame(['2026_01_01_000001_criar_setores', '2026_01_01_000002_criar_cargos'], $executor->migrar());
        self::assertTrue(Tabela::existe('setores'));
        self::assertTrue(Tabela::existe('cargos'));
        self::assertSame([], $executor->migrar());

        self::assertSame(['2026_01_01_000002_criar_cargos', '2026_01_01_000001_criar_setores'], $executor->reverter());
        self::assertFalse(Tabela::existe('setores'));
    }
}
