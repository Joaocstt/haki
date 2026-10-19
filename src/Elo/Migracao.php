<?php

declare(strict_types=1);

namespace Elo;

/**
 * Base de um arquivo de migração. O arquivo retorna uma classe anônima:
 *
 *     return new class extends Migracao {
 *         public function subir(): void { Tabela::criar('setores', [...]); }
 *         public function descer(): void { Tabela::apagar('setores'); }
 *     };
 */
abstract class Migracao
{
    /** Aplica a mudança (criar tabelas, colunas...). */
    abstract public function subir(): void;

    /** Desfaz exatamente o que subir() fez. */
    abstract public function descer(): void;
}
