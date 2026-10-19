<?php

declare(strict_types=1);

namespace Haki\Tests\Elo;

use Elo\Coluna;
use Elo\Conexao;
use Elo\Excecoes\EloException;
use Elo\Tabela;
use Haki\Tests\Fixtures\Elo\Cargo;
use Haki\Tests\Fixtures\Elo\Perfil;
use Haki\Tests\Fixtures\Elo\Produto;
use Haki\Tests\Fixtures\Elo\Setor;
use Haki\Tests\Fixtures\Elo\Usuario;
use PHPUnit\Framework\TestCase;

/**
 * Testes de ponta a ponta com SQLite em memória.
 */
final class EloIntegracaoTest extends TestCase
{
    protected function setUp(): void
    {
        Conexao::limpar();
        Conexao::configurar(['driver' => 'sqlite', 'arquivo' => ':memory:']);

        Tabela::criar('setores', ['nome' => Coluna::texto(100), 'sigla' => Coluna::texto(15)->unico()]);
        Tabela::criar('usuarios', [
            'nome' => Coluna::texto(150),
            'email' => Coluna::texto()->unico(),
            'senha' => Coluna::texto(100)->nulo(),
            'status' => Coluna::opcoes(['ATIVO', 'INATIVO'])->padrao('ATIVO'),
            'setor_id' => Coluna::pertenceA('setores')->aoExcluir('CASCADE'),
        ]);
        Tabela::criar('perfis', [
            'usuario_id' => Coluna::pertenceA('usuarios')->aoExcluir('CASCADE'),
            'bio' => Coluna::textoLongo()->nulo(),
        ]);
        Tabela::criar('cargos', ['nome' => Coluna::texto(50)]);
        Tabela::criar('cargo_usuario', [
            'usuario_id' => Coluna::pertenceA('usuarios')->aoExcluir('CASCADE'),
            'cargo_id' => Coluna::pertenceA('cargos')->aoExcluir('CASCADE'),
        ], ['sem_id' => true, 'sem_datas' => true]);
        Tabela::criar('produtos', ['nome' => Coluna::texto(80)], ['exclusao_logica' => true]);
    }

    protected function tearDown(): void
    {
        Conexao::limpar();
    }

    public function testCriarHidrataIdEDatas(): void
    {
        $setor = Setor::criar(['nome' => 'Núcleo de Apoio Técnico', 'sigla' => 'NAT']);

        $usuario = Usuario::criar([
            'nome' => 'João Cláudio',
            'email' => 'joao@exemplo.br',
            'setor_id' => $setor->id,
        ]);

        self::assertSame(1, $usuario->id);
        self::assertNotNull($usuario->criado_em);
        self::assertSame('NAT', $usuario->setor->sigla);
    }

    public function testMassAssignmentProtegeSoAChavePrimaria(): void
    {
        $usuario = new Usuario(['id' => 99, 'nome' => 'Ana']);

        self::assertSame('Ana', $usuario->nome);
        self::assertNull($usuario->id);
    }

    public function testDirtyCheckingGravaSoOQueMudou(): void
    {
        $usuario = Usuario::criar(['nome' => 'Ana', 'email' => 'ana@x.br']);
        $criadoEm = $usuario->criado_em;

        $usuario->nome = 'Ana Maria';
        $usuario->salvar();

        $recarregado = Usuario::buscar($usuario->id);
        self::assertSame('Ana Maria', $recarregado->nome);
        self::assertSame($criadoEm, $recarregado->criado_em);
    }

    public function testExcluirLogicoEsconde(): void
    {
        $produto = Produto::criar(['nome' => 'Espada']);
        $produto->excluir();

        self::assertNull(Produto::buscar($produto->id));
        self::assertNotNull(Produto::comExcluidos()->onde('id', '=', $produto->id)->primeiro());
    }

    public function testJsonEsconde(): void
    {
        $usuario = Usuario::criar(['nome' => 'Ana', 'email' => 'a@x.br', 'senha' => 'segredo']);

        self::assertArrayNotHasKey('senha', $usuario->jsonSerialize());
    }

    public function testTemUmCriaVinculado(): void
    {
        $usuario = Usuario::criar(['nome' => 'Ana', 'email' => 'a@x.br']);
        $perfil = $usuario->perfil()->criar(['bio' => 'Olá']);

        self::assertSame($usuario->id, $perfil->usuario_id);
        self::assertSame('Olá', Usuario::buscar($usuario->id)->perfil->bio);
    }

    public function testPertenceAMuitosSincronizar(): void
    {
        $usuario = Usuario::criar(['nome' => 'Ana', 'email' => 'a@x.br']);
        $a = Cargo::criar(['nome' => 'A']);
        $b = Cargo::criar(['nome' => 'B']);
        $c = Cargo::criar(['nome' => 'C']);

        $usuario->cargos()->sincronizar([$a->id, $b->id]);
        self::assertCount(2, $usuario->cargos);

        $resultado = $usuario->cargos()->sincronizar([$b->id, $c->id]);
        self::assertSame([$c->id], $resultado['anexados']);
        self::assertSame([$a->id], $resultado['desanexados']);

        $recarregado = Usuario::buscar($usuario->id);
        self::assertCount(2, $recarregado->cargos);
    }

    public function testEagerLoadingEvitaN1(): void
    {
        $setor = Setor::criar(['nome' => 'TI', 'sigla' => 'TI']);
        $ana = Usuario::criar(['nome' => 'Ana', 'email' => 'a@x.br', 'setor_id' => $setor->id]);
        $bruno = Usuario::criar(['nome' => 'Bruno', 'email' => 'b@x.br', 'setor_id' => $setor->id]);
        $cargo = Cargo::criar(['nome' => 'Dev']);
        $ana->cargos()->anexar($cargo->id);
        Perfil::criar(['usuario_id' => $bruno->id, 'bio' => 'Bio do Bruno']);

        $usuarios = Usuario::com('setor', 'cargos', 'perfil')->ordenarPor('id')->obter();

        self::assertCount(2, $usuarios);
        self::assertSame('TI', $usuarios[0]->setor->sigla);
        self::assertCount(1, $usuarios[0]->cargos);
        self::assertSame('Bio do Bruno', $usuarios[1]->perfil->bio);
        self::assertNull($usuarios[0]->perfil);
    }

    public function testPaginar(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Cargo::criar(['nome' => "Cargo {$i}"]);
        }

        $pagina = Cargo::consulta()->ordenarPor('id')->paginar(2, 3);

        self::assertSame(5, $pagina->total);
        self::assertSame(3, $pagina->ultima_pagina);
        self::assertCount(1, $pagina->dados);
    }

    public function testOuOndeNaoVazaParaExclusaoLogica(): void
    {
        $ativo = Produto::criar(['nome' => 'Ativo']);
        $excluido = Produto::criar(['nome' => 'Excluído']);
        $excluido->excluir();

        $resultado = Produto::onde('nome', 'Excluído')->ouOnde('nome', 'Ativo')->obter();

        self::assertCount(1, $resultado);
        self::assertSame($ativo->id, $resultado[0]->id);
    }

    public function testOndeEmListaVaziaNaoQuebra(): void
    {
        Cargo::criar(['nome' => 'X']);

        self::assertSame([], Cargo::consulta()->ondeEm('id', [])->obter());
    }

    public function testOperadorInvalidoEBloqueado(): void
    {
        $this->expectException(EloException::class);

        Cargo::consulta()->onde('id', 'OR 1=1 --', 1);
    }

    public function testTransacaoDesfazNaFalha(): void
    {
        try {
            Conexao::transacao(function (): void {
                Cargo::criar(['nome' => 'Temporário']);
                throw new \RuntimeException('falhou');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, Cargo::consulta()->contar());
    }
}
