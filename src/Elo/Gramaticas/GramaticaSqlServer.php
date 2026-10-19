<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;
use Elo\Excecoes\EloException;

/**
 * Microsoft SQL Server: colchetes, IDENTITY, NVARCHAR, OFFSET/FETCH e OUTPUT para pegar o id.
 */
final class GramaticaSqlServer extends GramaticaBase
{
    public function nome(): string
    {
        return 'sqlsrv';
    }

    protected function envolver(string $nome): string
    {
        return '[' . $nome . ']';
    }

    protected function tipoChavePrimaria(): string
    {
        return 'BIGINT IDENTITY(1,1) PRIMARY KEY';
    }

    protected function tipoColuna(Coluna $coluna): string
    {
        return match ($coluna->tipo) {
            'texto' => "NVARCHAR({$coluna->tamanho})",
            'textoLongo' => 'NVARCHAR(MAX)',
            'inteiro' => 'INT',
            'decimal' => "DECIMAL({$coluna->tamanho}, {$coluna->escala})",
            'booleano' => 'BIT',
            'opcoes' => $this->tipoOpcoes($coluna),
            'data' => 'DATE',
            'dataHora', 'timestamp' => 'DATETIME2',
            'json' => 'NVARCHAR(MAX)',
            'referencia' => 'BIGINT',
            default => throw new EloException("Tipo de coluna desconhecido para SQL Server: {$coluna->tipo}."),
        };
    }

    protected function tipoOpcoes(Coluna $coluna): string
    {
        $maior = 1;

        foreach ($coluna->opcoes as $opcao) {
            $maior = max($maior, strlen($opcao));
        }

        return "NVARCHAR({$maior})";
    }

    protected function acaoReferencia(string $acao): string
    {
        // O SQL Server chama RESTRICT de NO ACTION.
        return $acao === 'RESTRICT' ? 'NO ACTION' : $acao;
    }

    protected function clausulaSaida(string $pk): string
    {
        return 'OUTPUT INSERTED.' . $this->identificador($pk);
    }

    public function retornaIdNaInsercao(): bool
    {
        return true;
    }

    public function exigeOrdenacaoParaPaginar(): bool
    {
        return true;
    }

    public function paginacao(?int $limite, ?int $pular): string
    {
        if ($limite === null && $pular === null) {
            return '';
        }

        $sql = 'OFFSET ' . ($pular ?? 0) . ' ROWS';

        if ($limite !== null) {
            $sql .= " FETCH NEXT {$limite} ROWS ONLY";
        }

        return $sql;
    }

    public function consultaTabelaExiste(): string
    {
        return 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ?';
    }

    protected function prefixoAdicionarColuna(): string
    {
        return 'ADD';
    }
}
