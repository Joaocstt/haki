<?php

declare(strict_types=1);

namespace Haki\Tests\Elo;

use Elo\Coluna;
use Elo\Excecoes\EloException;
use Elo\Gramaticas\GramaticaMySql;
use Elo\Gramaticas\GramaticaPostgres;
use Elo\Gramaticas\GramaticaSqlServer;
use Elo\Gramaticas\GramaticaSqlite;
use PHPUnit\Framework\TestCase;

/**
 * Confere o SQL gerado para cada banco, sem precisar de conexão.
 */
final class DdlTest extends TestCase
{
    private function colunas(): array
    {
        return [
            'id' => Coluna::id(),
            'nome' => Coluna::texto(100),
            'status' => Coluna::opcoes(['ATIVO', 'INATIVO'])->padrao('ATIVO'),
            'ativo' => Coluna::booleano()->padrao(true),
            'setor_id' => Coluna::pertenceA('setores')->aoExcluir('CASCADE'),
            'criado_em' => Coluna::timestamp(),
        ];
    }

    public function testMySqlUsaCrasENumNativo(): void
    {
        $sql = (new GramaticaMySql())->compilarCriarTabela('usuarios', $this->colunas())[0];

        self::assertStringContainsString('CREATE TABLE `usuarios`', $sql);
        self::assertStringContainsString('`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $sql);
        self::assertStringContainsString("ENUM('ATIVO', 'INATIVO')", $sql);
        self::assertStringContainsString('`ativo` TINYINT(1) NOT NULL DEFAULT 1', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testPostgresUsaIdentityEAspasDuplas(): void
    {
        $sql = (new GramaticaPostgres())->compilarCriarTabela('usuarios', $this->colunas())[0];

        self::assertStringContainsString('"id" BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY', $sql);
        self::assertStringContainsString('"ativo" BOOLEAN NOT NULL DEFAULT TRUE', $sql);
        self::assertStringContainsString('CHECK ("status" IN (\'ATIVO\', \'INATIVO\'))', $sql);
    }

    public function testSqlServerUsaColchetesEDatetime2(): void
    {
        $sql = (new GramaticaSqlServer())->compilarCriarTabela('usuarios', $this->colunas())[0];

        self::assertStringContainsString('[id] BIGINT IDENTITY(1,1) PRIMARY KEY', $sql);
        self::assertStringContainsString('[criado_em] DATETIME2 NULL', $sql);
        self::assertStringContainsString('[nome] NVARCHAR(100)', $sql);
    }

    public function testSqliteRemoveRestricaoEmAlterar(): void
    {
        $this->expectException(EloException::class);

        (new GramaticaSqlite())->compilarAlterarTabela('usuarios', [
            'setor_id' => Coluna::pertenceA('setores'),
        ], []);
    }

    public function testRejeitaNomeComInjecao(): void
    {
        $this->expectException(EloException::class);

        (new GramaticaMySql())->identificador('usuarios; DROP TABLE x');
    }

    public function testSqlServerInsereComOutputEPaginaComFetch(): void
    {
        $gramatica = new GramaticaSqlServer();

        self::assertSame(
            'INSERT INTO [usuarios] ([nome]) OUTPUT INSERTED.[id] VALUES (?)',
            $gramatica->compilarInsercao('usuarios', ['nome'], 'id'),
        );
        self::assertSame('OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', $gramatica->paginacao(10, 20));
    }

    public function testSqlitePaginaSoComPularUsaLimiteNegativo(): void
    {
        self::assertSame('LIMIT -1 OFFSET 5', (new GramaticaSqlite())->paginacao(null, 5));
    }
}
