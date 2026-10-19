<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;
use Elo\Excecoes\EloException;

/**
 * SQLite: aspas duplas, AUTOINCREMENT e tipos simples. Ideal para testes com :memory:.
 *
 * Limitações do próprio SQLite, que o EloPHP avisa antes de executar:
 * - ALTER TABLE ... ADD não aceita UNIQUE nem chave estrangeira;
 * - para criar essas restrições, é preciso recriar a tabela.
 */
final class GramaticaSqlite extends GramaticaBase
{
    public function nome(): string
    {
        return 'sqlite';
    }

    protected function envolver(string $nome): string
    {
        return '"' . $nome . '"';
    }

    protected function tipoChavePrimaria(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function tipoColuna(Coluna $coluna): string
    {
        return match ($coluna->tipo) {
            'texto' => "VARCHAR({$coluna->tamanho})",
            'textoLongo' => 'TEXT',
            'inteiro' => 'INTEGER',
            'decimal' => "NUMERIC({$coluna->tamanho}, {$coluna->escala})",
            'booleano' => 'BOOLEAN',
            'opcoes' => $this->tipoOpcoes($coluna),
            'data' => 'DATE',
            'dataHora', 'timestamp' => 'DATETIME',
            'json' => 'TEXT',
            'referencia' => 'INTEGER',
            default => throw new EloException("Tipo de coluna desconhecido para SQLite: {$coluna->tipo}."),
        };
    }

    public function compilarAlterarTabela(string $tabela, array $adicionar, array $remover): array
    {
        foreach ($adicionar as $nome => $coluna) {
            if ($coluna->unico) {
                throw new EloException("SQLite não aceita UNIQUE em ALTER TABLE (coluna \"{$nome}\"). Crie a coluna sem unico() ou recrie a tabela.");
            }

            if ($coluna->referencia !== null) {
                throw new EloException("SQLite não aceita chave estrangeira em ALTER TABLE (coluna \"{$nome}\"). Recrie a tabela com Tabela::criar().");
            }
        }

        return parent::compilarAlterarTabela($tabela, $adicionar, $remover);
    }

    public function paginacao(?int $limite, ?int $pular): string
    {
        if ($limite === null && $pular === null) {
            return '';
        }

        // SQLite não aceita OFFSET sem LIMIT; -1 significa "sem limite".
        $sql = 'LIMIT ' . ($limite ?? -1);

        return $pular !== null ? $sql . " OFFSET {$pular}" : $sql;
    }

    public function consultaTabelaExiste(): string
    {
        return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
    }
}
