<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;

/**
 * Tradução do EloPHP para o dialeto de cada banco (DDL e partes do DML).
 *
 * O Construtor monta o SELECT com a mesma lógica para todos os bancos e
 * pede à gramática só o que muda de um banco para outro.
 */
interface ContratoGramatica
{
    /** Nome do driver PDO: mysql, pgsql, sqlsrv ou sqlite. */
    public function nome(): string;

    /** Coloca aspas no nome (com validação): usuarios -> `usuarios` / "usuarios" / [usuarios]. */
    public function identificador(string $nome): string;

    /**
     * @param array<string, Coluna> $colunas colunas completas, com id e datas já incluídas
     * @return list<string> comandos SQL, nesta ordem
     */
    public function compilarCriarTabela(string $tabela, array $colunas): array;

    /**
     * @param array<string, Coluna> $adicionar
     * @param list<string> $remover
     * @return list<string>
     */
    public function compilarAlterarTabela(string $tabela, array $adicionar, array $remover): array;

    public function compilarApagarTabela(string $tabela): string;

    /** SQL com um ? (nome da tabela) que retorna uma linha quando a tabela existe. */
    public function consultaTabelaExiste(): string;

    /**
     * @param list<string> $colunas
     */
    public function compilarInsercao(string $tabela, array $colunas, string $pk): string;

    /** true quando o banco devolve o id dentro do próprio INSERT (PostgreSQL, SQL Server). */
    public function retornaIdNaInsercao(): bool;

    public function paginacao(?int $limite, ?int $pular): string;

    /** SQL Server exige ORDER BY para usar OFFSET/FETCH. */
    public function exigeOrdenacaoParaPaginar(): bool;
}
