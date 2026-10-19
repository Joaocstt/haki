<?php

declare(strict_types=1);

namespace Haki\Tests\Elo;

use Elo\Suporte\Pluralizador;
use PHPUnit\Framework\TestCase;

final class PluralizadorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nomes(): iterable
    {
        yield 'regra geral' => ['Usuario', 'usuarios'];
        yield 'ao -> oes' => ['Movimentacao', 'movimentacoes'];
        yield 'm -> ns' => ['Item', 'itens'];
        yield 'al -> ais' => ['Edital', 'editais'];
        yield 'el -> eis' => ['Papel', 'papeis'];
        yield 'il -> is' => ['Funil', 'funis'];
        yield 'r -> es' => ['Setor', 'setores'];
        yield 'z -> es' => ['Juiz', 'juizes'];
        yield 'exceção do dicionário' => ['Cidadao', 'cidadaos'];
        yield 'exceção perfil' => ['Perfil', 'perfis'];
        yield 'composto' => ['ProcessoJudicial', 'processos_judiciais'];
    }

    /**
     * @dataProvider nomes
     */
    public function testTabelaDeClasse(string $classe, string $tabela): void
    {
        self::assertSame($tabela, Pluralizador::tabelaDe($classe));
    }

    public function testSnakeNaoPluraliza(): void
    {
        self::assertSame('processo_judicial', Pluralizador::snake('ProcessoJudicial'));
    }
}
