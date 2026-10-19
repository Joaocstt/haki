<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;
use Elo\Excecoes\EloException;

/**
 * MySQL e MariaDB: crases (`), AUTO_INCREMENT, ENUM e UNSIGNED nativos.
 */
final class GramaticaMySql extends GramaticaBase
{
    public function nome(): string
    {
        return 'mysql';
    }

    protected function envolver(string $nome): string
    {
        return '`' . $nome . '`';
    }

    protected function tipoChavePrimaria(): string
    {
        return 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    }

    protected function tipoColuna(Coluna $coluna): string
    {
        return match ($coluna->tipo) {
            'texto' => "VARCHAR({$coluna->tamanho})",
            'textoLongo' => 'TEXT',
            'inteiro' => $coluna->semSinal ? 'INT UNSIGNED' : 'INT',
            'decimal' => "DECIMAL({$coluna->tamanho}, {$coluna->escala})",
            'booleano' => 'TINYINT(1)',
            'opcoes' => 'ENUM(' . implode(', ', array_map(fn (string $o) => "'" . $this->escaparTexto($o) . "'", $coluna->opcoes)) . ')',
            'data' => 'DATE',
            'dataHora' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'json' => 'JSON',
            'referencia' => 'BIGINT UNSIGNED',
            default => throw new EloException("Tipo de coluna desconhecido para MySQL: {$coluna->tipo}."),
        };
    }

    protected function enumNativo(): bool
    {
        return true;
    }

    protected function inteiroSemSinalNativo(): bool
    {
        return true;
    }

    protected function valoresVazios(): string
    {
        return '() VALUES ()';
    }

    /** No MySQL, a barra invertida também escapa dentro de strings. */
    protected function escaparTexto(string $texto): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $texto);
    }

    public function paginacao(?int $limite, ?int $pular): string
    {
        if ($limite === null && $pular === null) {
            return '';
        }

        // MySQL não aceita OFFSET sem LIMIT; o maior valor possível faz o papel de "sem limite".
        $sql = 'LIMIT ' . ($limite ?? '18446744073709551615');

        return $pular !== null ? $sql . " OFFSET {$pular}" : $sql;
    }

    public function consultaTabelaExiste(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    }
}
