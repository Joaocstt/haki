<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;
use Elo\Excecoes\EloException;

/**
 * PostgreSQL: aspas duplas, GENERATED ALWAYS AS IDENTITY e RETURNING para pegar o id do insert.
 */
final class GramaticaPostgres extends GramaticaBase
{
    public function nome(): string
    {
        return 'pgsql';
    }

    protected function envolver(string $nome): string
    {
        return '"' . $nome . '"';
    }

    protected function tipoChavePrimaria(): string
    {
        return 'BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
    }

    protected function tipoColuna(Coluna $coluna): string
    {
        return match ($coluna->tipo) {
            'texto' => "VARCHAR({$coluna->tamanho})",
            'textoLongo' => 'TEXT',
            'inteiro' => 'INTEGER',
            'decimal' => "DECIMAL({$coluna->tamanho}, {$coluna->escala})",
            'booleano' => 'BOOLEAN',
            'opcoes' => $this->tipoOpcoes($coluna),
            'data' => 'DATE',
            'dataHora', 'timestamp' => 'TIMESTAMP',
            'json' => 'JSON',
            'referencia' => 'BIGINT',
            default => throw new EloException("Tipo de coluna desconhecido para PostgreSQL: {$coluna->tipo}."),
        };
    }

    protected function booleano(bool $valor): string
    {
        return $valor ? 'TRUE' : 'FALSE';
    }

    protected function clausulaRetorno(string $pk): string
    {
        return 'RETURNING ' . $this->identificador($pk);
    }

    public function retornaIdNaInsercao(): bool
    {
        return true;
    }

    public function consultaTabelaExiste(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }
}
