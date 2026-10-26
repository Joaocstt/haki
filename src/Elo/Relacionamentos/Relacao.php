<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Excecoes\EloException;
use Elo\Modelo;
use Elo\Suporte\Pluralizador;

/**
 * Motor de um relacionamento. Criado sob demanda (lazy): nada é consultado até ser usado.
 *
 * Com parênteses, $usuario->cargos() devolve este objeto para encadear filtros:
 *     $usuario->cargos()->onde('ativo', true)->obter()
 *
 * Sem parênteses, $usuario->cargos devolve o resultado carregado (com cache em memória).
 */
abstract class Relacao
{
    protected string $classeAlvo;

    /** @var array<string, mixed> opções extras, como ['fk' => 'autor_id'] */
    protected array $opcoes = [];

    private ?Construtor $consultaAtual = null;

    /**
     * @param string|array<int|string, mixed> $definicao classe do alvo, ou [Classe::class, 'fk' => ...]
     */
    public function __construct(
        protected Modelo $pai,
        protected string $nome,
        string|array $definicao,
    ) {
        if (is_string($definicao)) {
            $this->classeAlvo = $definicao;

            return;
        }

        if (!isset($definicao[0]) || !is_string($definicao[0])) {
            throw new EloException("A relação \"{$nome}\" precisa informar a classe do modelo relacionado, por exemplo: [Perfil::class, 'fk' => 'usuario_id'].");
        }

        $this->classeAlvo = $definicao[0];
        $this->opcoes = $definicao;
        unset($this->opcoes[0]);
    }

    /**
     * @param 'temUm'|'temMuitos'|'pertenceA'|'pertenceAMuitos' $tipo
     * @param string|array<int|string, mixed> $definicao
     */
    public static function paraTipo(string $tipo, Modelo $pai, string $nome, string|array $definicao): self
    {
        return match ($tipo) {
            'temUm' => new RelacaoUmParaUm($pai, $nome, $definicao),
            'temMuitos' => new RelacaoUmParaMuitos($pai, $nome, $definicao),
            'pertenceA' => new RelacaoPertenceA($pai, $nome, $definicao),
            'pertenceAMuitos' => new RelacaoMuitosParaMuitos($pai, $nome, $definicao),
            default => throw new EloException("Tipo de relacionamento desconhecido: {$tipo}."),
        };
    }

    /**
     * Resultado da relação (um model, uma lista ou null), conforme o tipo.
     */
    abstract public function obter(): mixed;

    /**
     * Permite encadear filtros do Construtor: ->onde(...), ->ordenarPor(...), ->com(...).
     *
     * @param list<mixed> $argumentos
     */
    public function __call(string $metodo, array $argumentos): mixed
    {
        $consulta = $this->consulta();

        if (!is_callable([$consulta, $metodo])) {
            throw new EloException("A relação \"{$this->nome}\" não tem o método \"{$metodo}\".");
        }

        $resultado = $consulta->{$metodo}(...$argumentos);

        return $resultado === $consulta ? $this : $resultado;
    }

    /** @internal */
    public static function nomeCurto(string $classe): string
    {
        $posicao = strrpos($classe, '\\');

        return $posicao === false ? $classe : substr($classe, $posicao + 1);
    }

    abstract protected function montarConsulta(): Construtor;

    protected function consulta(): Construtor
    {
        return $this->consultaAtual ??= $this->montarConsulta();
    }

    /** Chave estrangeira padrão quando o pai é quem "tem": Usuario -> usuario_id. */
    protected function chaveDoPai(): string
    {
        return Pluralizador::snake(self::nomeCurto($this->pai::class)) . '_id';
    }

    protected function fkNoAlvo(): string
    {
        return $this->opcoes['fk'] ?? $this->chaveDoPai();
    }

    protected function idDoPai(): mixed
    {
        return $this->pai->{$this->pai->nomeChavePrimaria()};
    }

    protected function alvo(): Modelo
    {
        return new ($this->classeAlvo)();
    }

    /**
     * Filtra pela chave do pai. Pai ainda não salvo: a lista fica vazia, sem consultar nada útil.
     */
    protected function filtrarPorPai(Construtor $consulta, string $coluna): Construtor
    {
        $id = $this->idDoPai();

        return $id === null ? $consulta->ondeEm($coluna, []) : $consulta->onde($coluna, '=', $id);
    }

    /**
     * Cria um registro do alvo já vinculado ao pai (usado por temUm e temMuitos).
     *
     * @param array<string, mixed> $dados
     */
    protected function criarNoAlvo(array $dados): Modelo
    {
        $id = $this->idDoPai();

        if ($id === null) {
            throw new EloException('Salve o modelo pai com salvar() antes de criar registros relacionados.');
        }

        $dados[$this->fkNoAlvo()] = $id;

        $modelo = new ($this->classeAlvo)($dados);
        $modelo->salvar();

        return $modelo;
    }
}
