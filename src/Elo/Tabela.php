<?php

declare(strict_types=1);

namespace Elo;

use Elo\Excecoes\EloException;

/**
 * Fachada do esquema do banco: criar, alterar e apagar tabelas com arrays declarativos.
 *
 *     Tabela::criar('usuarios', [
 *         'nome'     => Coluna::texto(150),
 *         'setor_id' => Coluna::pertenceA('setores')->aoExcluir('CASCADE'),
 *     ]);
 *
 * Por padrão, criar() acrescenta id (no início) e criado_em/atualizado_em (no fim).
 */
final class Tabela
{
    /**
     * @param array<string, Coluna> $colunas
     * @param array{sem_id?: bool, sem_datas?: bool, exclusao_logica?: bool} $opcoes
     */
    public static function criar(string $nome, array $colunas, array $opcoes = []): void
    {
        $todas = [];

        if (!($opcoes['sem_id'] ?? false)) {
            $todas['id'] = Coluna::id();
        }

        foreach ($colunas as $campo => $coluna) {
            self::validar($campo, $coluna, false);
            $todas[$campo] = $coluna;
        }

        if (!($opcoes['sem_datas'] ?? false)) {
            $todas['criado_em'] ??= Coluna::timestamp();
            $todas['atualizado_em'] ??= Coluna::timestamp();
        }

        if (($opcoes['exclusao_logica'] ?? false) === true) {
            $todas['excluido_em'] ??= Coluna::exclusaoLogica();
        }

        foreach (Conexao::gramatica()->compilarCriarTabela($nome, $todas) as $sql) {
            Conexao::executarDdl($sql);
        }
    }

    /**
     * Adiciona colunas e remove as marcadas com Coluna::remover().
     *
     * @param array<string, Coluna> $colunas
     */
    public static function alterar(string $nome, array $colunas): void
    {
        $adicionar = [];
        $remover = [];

        foreach ($colunas as $campo => $coluna) {
            self::validar($campo, $coluna, true);

            if ($coluna->tipo === 'remover') {
                $remover[] = $campo;
            } else {
                $adicionar[$campo] = $coluna;
            }
        }

        foreach (Conexao::gramatica()->compilarAlterarTabela($nome, $adicionar, $remover) as $sql) {
            Conexao::executarDdl($sql);
        }
    }

    /** Remove a tabela, se ela existir. */
    public static function apagar(string $nome): void
    {
        Conexao::executarDdl(Conexao::gramatica()->compilarApagarTabela($nome));
    }

    public static function existe(string $nome): bool
    {
        return Conexao::executar(Conexao::gramatica()->consultaTabelaExiste(), [$nome])->fetchColumn() !== false;
    }

    private static function validar(mixed $campo, mixed $coluna, bool $permiteRemover): void
    {
        if (!is_string($campo)) {
            throw new EloException('Cada coluna precisa de um nome: \'nome\' => Coluna::texto(100).');
        }

        if (!$coluna instanceof Coluna) {
            throw new EloException("A coluna \"{$campo}\" precisa ser definida com Coluna::, por exemplo: Coluna::texto(100).");
        }

        if ($coluna->tipo === 'remover' && !$permiteRemover) {
            throw new EloException("Coluna::remover() só funciona em Tabela::alterar(), não em Tabela::criar() (coluna \"{$campo}\").");
        }
    }
}
