<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Excecoes\EloException;
use Elo\Modelo;
use Elo\Suporte\Pluralizador;

/**
 * Carregamento ansioso (eager loading) das relações pedidas com com().
 *
 * Em vez de uma consulta por registro (N+1), faz uma única consulta por relação,
 * com WHERE ... IN (...), e distribui os resultados para cada model.
 *
 * @internal
 */
final class CarregadorDeRelacoes
{
    /**
     * @param list<Modelo> $modelos
     * @param list<string> $nomes
     * @param class-string<Modelo> $classe
     */
    public static function carregar(array $modelos, array $nomes, string $classe): void
    {
        $molde = new $classe();

        foreach ($nomes as $nome) {
            $definicao = $molde->definicaoRelacao($nome);

            if ($definicao === null) {
                throw new EloException("A relação \"{$nome}\" não existe em {$classe}.");
            }

            $def = $definicao['definicao'];
            $classeAlvo = is_string($def) ? $def : (string) ($def[0] ?? '');
            $opcoes = is_array($def) ? $def : [];

            if ($classeAlvo === '') {
                throw new EloException("A relação \"{$nome}\" precisa informar a classe do modelo relacionado.");
            }

            match ($definicao['tipo']) {
                'temUm', 'temMuitos' => self::porChaveNoAlvo($modelos, $molde, $nome, $definicao['tipo'], $classeAlvo, $opcoes),
                'pertenceA' => self::pertenceA($modelos, $nome, $classeAlvo, $opcoes),
                'pertenceAMuitos' => self::pertenceAMuitos($modelos, $molde, $nome, $classeAlvo, $opcoes),
                default => null,
            };
        }
    }

    /**
     * temUm e temMuitos: o alvo guarda a chave do pai.
     *
     * @param list<Modelo> $modelos
     * @param array<string, mixed> $opcoes
     */
    private static function porChaveNoAlvo(array $modelos, Modelo $molde, string $nome, string $tipo, string $classeAlvo, array $opcoes): void
    {
        $chave = $molde->nomeChavePrimaria();
        $fk = (string) ($opcoes['fk'] ?? Pluralizador::snake(Relacao::nomeCurto($molde::class)) . '_id');

        $ids = self::valoresUnicos($modelos, $chave);
        $encontrados = $ids === [] ? [] : Construtor::para($classeAlvo)->ondeEm($fk, $ids)->obter();

        $grupos = [];

        foreach ($encontrados as $item) {
            $grupos[(string) $item->{$fk}][] = $item;
        }

        foreach ($modelos as $modelo) {
            $grupo = $grupos[(string) $modelo->{$chave}] ?? [];
            $modelo->definirRelacao($nome, $tipo === 'temUm' ? ($grupo[0] ?? null) : $grupo);
        }
    }

    /**
     * pertenceA: a chave está no próprio model.
     *
     * @param list<Modelo> $modelos
     * @param array<string, mixed> $opcoes
     */
    private static function pertenceA(array $modelos, string $nome, string $classeAlvo, array $opcoes): void
    {
        $fk = (string) ($opcoes['fk'] ?? Pluralizador::snake($nome) . '_id');
        $chaveAlvo = (new $classeAlvo())->nomeChavePrimaria();

        $valores = self::valoresUnicos($modelos, $fk);
        $encontrados = $valores === [] ? [] : Construtor::para($classeAlvo)->ondeEm($chaveAlvo, $valores)->obter();

        $indice = [];

        foreach ($encontrados as $item) {
            $indice[(string) $item->{$chaveAlvo}] = $item;
        }

        foreach ($modelos as $modelo) {
            $valor = $modelo->{$fk};
            $modelo->definirRelacao($nome, $valor === null ? null : ($indice[(string) $valor] ?? null));
        }
    }

    /**
     * pertenceAMuitos: uma consulta junta o alvo com a tabela pivô e traz a chave do pai junto.
     *
     * @param list<Modelo> $modelos
     * @param array<string, mixed> $opcoes
     */
    private static function pertenceAMuitos(array $modelos, Modelo $molde, string $nome, string $classeAlvo, array $opcoes): void
    {
        $chavePai = $molde->nomeChavePrimaria();
        $pivo = RelacaoMuitosParaMuitos::resolverPivo($molde::class, $classeAlvo, $opcoes);

        $alvo = new $classeAlvo();
        $tabelaAlvo = $alvo->nomeTabela();
        $chaveAlvo = $alvo->nomeChavePrimaria();

        $ids = self::valoresUnicos($modelos, $chavePai);

        $encontrados = $ids === [] ? [] : Construtor::para($classeAlvo)
            ->juntar($pivo['pivo'], $tabelaAlvo . '.' . $chaveAlvo, $pivo['pivo'] . '.' . $pivo['chave_alvo'])
            ->selecionar($tabelaAlvo . '.*', $pivo['pivo'] . '.' . $pivo['chave_pai'] . ' AS _elo_pai')
            ->ondeEm($pivo['pivo'] . '.' . $pivo['chave_pai'], $ids)
            ->obter();

        $grupos = [];

        foreach ($encontrados as $item) {
            $dono = (string) $item->_elo_pai;
            $item->removerAtributo('_elo_pai');
            $grupos[$dono][] = $item;
        }

        foreach ($modelos as $modelo) {
            $modelo->definirRelacao($nome, $grupos[(string) $modelo->{$chavePai}] ?? []);
        }
    }

    /**
     * Valores distintos e não nulos de uma coluna, para o WHERE ... IN (...).
     *
     * @param list<Modelo> $modelos
     * @return list<mixed>
     */
    private static function valoresUnicos(array $modelos, string $campo): array
    {
        $valores = [];

        foreach ($modelos as $modelo) {
            $valor = $modelo->{$campo};

            if ($valor !== null) {
                $valores[(string) $valor] = $valor;
            }
        }

        return array_values($valores);
    }
}
