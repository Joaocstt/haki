<?php

declare(strict_types=1);

namespace Elo\Suporte;

/**
 * Converte nomes de classes em nomes de tabelas, com regras do português.
 *
 *     Usuario          -> usuarios
 *     Cidadao          -> cidadaos        (exceção)
 *     Setor            -> setores
 *     Edital           -> editais
 *     ProcessoJudicial -> processos_judiciais
 */
final class Pluralizador
{
    /** @var array<string, string> */
    private static array $excecoes = [
        'cidadao' => 'cidadaos',
        'mao' => 'maos',
        'perfil' => 'perfis',
    ];

    /**
     * Registra uma exceção ao dicionário, ex.: Pluralizador::excecao('vilao', 'viloes').
     */
    public static function excecao(string $singular, string $plural): void
    {
        self::$excecoes[strtolower($singular)] = strtolower($plural);
    }

    /**
     * Nome da tabela para um nome de classe em PascalCase.
     */
    public static function tabelaDe(string $nomeClasse): string
    {
        return implode('_', array_map([self::class, 'plural'], self::palavras($nomeClasse)));
    }

    /**
     * Nome em snake_case, sem pluralizar. Usado nas chaves estrangeiras: Usuario -> usuario_id.
     */
    public static function snake(string $nomeClasse): string
    {
        return implode('_', self::palavras($nomeClasse));
    }

    /**
     * Plural de uma palavra em minúsculas, seguindo as regras de sufixo do português.
     */
    public static function plural(string $palavra): string
    {
        $p = strtolower($palavra);

        if (isset(self::$excecoes[$p])) {
            return self::$excecoes[$p];
        }

        if (str_ends_with($p, 'ao')) {
            return substr($p, 0, -2) . 'oes';
        }

        if (str_ends_with($p, 'm')) {
            return substr($p, 0, -1) . 'ns';
        }

        foreach (['al', 'el', 'ol', 'ul'] as $fim) {
            if (str_ends_with($p, $fim)) {
                return substr($p, 0, -1) . 'is';
            }
        }

        if (str_ends_with($p, 'il')) {
            return substr($p, 0, -1) . 's';
        }

        if (preg_match('/[rzs]$/', $p) === 1) {
            return $p . 'es';
        }

        return $p . 's';
    }

    /**
     * @return list<string>
     */
    private static function palavras(string $nome): array
    {
        $partes = preg_split('/(?=[A-Z])/', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map('strtolower', $partes);
    }
}
