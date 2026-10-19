<?php

declare(strict_types=1);

namespace Elo;

use Elo\Excecoes\EloException;

/**
 * Definição de uma coluna. Tipo e modificadores são encadeados:
 *
 *     Coluna::texto(15)->unico()
 *     Coluna::opcoes(['ATIVO', 'INATIVO'])->padrao('ATIVO')
 *     Coluna::pertenceA('setores')->aoExcluir('CASCADE')
 *
 * A tradução para o SQL de cada banco fica na Gramática, não aqui.
 */
final class Coluna
{
    private const ACOES = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'];

    public bool $nulo = false;
    public bool $unico = false;
    public bool $indice = false;
    public bool $semSinal = false;
    public bool $padraoAtual = false;
    public bool $temPadrao = false;
    public mixed $padrao = null;
    /** @var array{tabela: string, coluna: string}|null */
    public ?array $referencia = null;
    public ?string $aoExcluir = null;
    public ?string $aoAtualizar = null;

    /**
     * @param list<string> $opcoes
     */
    private function __construct(
        public readonly string $tipo,
        public readonly int $tamanho = 255,
        public readonly int $escala = 0,
        public readonly array $opcoes = [],
    ) {
    }

    // ------------------------------------------------------------------ tipos

    /** Chave primária automática. Já vem em Tabela::criar(), salvo com 'sem_id'. */
    public static function id(): self
    {
        return new self('id');
    }

    public static function texto(int $tamanho = 255): self
    {
        if ($tamanho < 1 || $tamanho > 4000) {
            throw new EloException("O tamanho do texto precisa estar entre 1 e 4000 (recebido: {$tamanho}).");
        }

        return new self('texto', $tamanho);
    }

    public static function textoLongo(): self
    {
        return new self('textoLongo');
    }

    public static function inteiro(): self
    {
        return new self('inteiro');
    }

    /** Número decimal. $total = quantidade de dígitos, $casas = casas depois da vírgula. */
    public static function decimal(int $total = 10, int $casas = 2): self
    {
        if ($casas < 0 || $casas > $total) {
            throw new EloException("decimal({$total}, {$casas}): as casas decimais não podem passar do total de dígitos.");
        }

        return new self('decimal', $total, $casas);
    }

    public static function booleano(): self
    {
        return new self('booleano');
    }

    /**
     * Lista fechada de valores. Em MySQL vira ENUM; nos demais bancos, VARCHAR com CHECK.
     *
     * @param list<string> $lista
     */
    public static function opcoes(array $lista): self
    {
        $lista = array_values($lista);

        if ($lista === []) {
            throw new EloException('Coluna::opcoes() precisa de pelo menos uma opção.');
        }

        foreach ($lista as $opcao) {
            if (!is_string($opcao) || $opcao === '') {
                throw new EloException('Todas as opções de Coluna::opcoes() precisam ser textos não vazios.');
            }
        }

        return new self('opcoes', 0, 0, $lista);
    }

    public static function data(): self
    {
        return new self('data');
    }

    public static function dataHora(): self
    {
        return new self('dataHora');
    }

    /**
     * Carimbo de data e hora usado pela auditoria (criado_em, atualizado_em). Aceita NULL.
     */
    public static function timestamp(): self
    {
        $coluna = new self('timestamp');
        $coluna->nulo = true;

        return $coluna;
    }

    /** Coluna excluido_em da exclusão lógica. Aceita NULL. */
    public static function exclusaoLogica(): self
    {
        return self::timestamp();
    }

    public static function json(): self
    {
        return new self('json');
    }

    /**
     * Chave estrangeira. Deduz a referência: pertenceA('setores') -> setores(id).
     */
    public static function pertenceA(string $tabela, string $chave = 'id'): self
    {
        $coluna = new self('referencia');
        $coluna->referencia = ['tabela' => $tabela, 'coluna' => $chave];

        return $coluna;
    }

    /** Usado só em Tabela::alterar(), para remover uma coluna existente. */
    public static function remover(): self
    {
        return new self('remover');
    }

    // ---------------------------------------------------------- modificadores

    public function nulo(): self
    {
        $this->nulo = true;

        return $this;
    }

    public function unico(): self
    {
        $this->unico = true;

        return $this;
    }

    /** Cria um índice comum (CREATE INDEX). Colunas únicas já são indexadas. */
    public function indice(): self
    {
        $this->indice = true;

        return $this;
    }

    public function semSinal(): self
    {
        if (!in_array($this->tipo, ['inteiro', 'referencia'], true)) {
            throw new EloException("semSinal() só vale para inteiro() e pertenceA(), não para {$this->tipo}.");
        }

        $this->semSinal = true;

        return $this;
    }

    public function padrao(mixed $valor): self
    {
        $this->temPadrao = true;
        $this->padrao = $valor;

        return $this;
    }

    /** Valor padrão = data e hora do momento do insert (CURRENT_TIMESTAMP). */
    public function padraoAtual(): self
    {
        if (!in_array($this->tipo, ['data', 'dataHora', 'timestamp'], true)) {
            throw new EloException("padraoAtual() só vale para data(), dataHora() e timestamp(), não para {$this->tipo}.");
        }

        $this->padraoAtual = true;

        return $this;
    }

    public function aoExcluir(string $acao): self
    {
        $this->aoExcluir = $this->validarAcao($acao);

        return $this;
    }

    public function aoAtualizar(string $acao): self
    {
        $this->aoAtualizar = $this->validarAcao($acao);

        return $this;
    }

    private function validarAcao(string $acao): string
    {
        if ($this->referencia === null) {
            throw new EloException('aoExcluir() e aoAtualizar() só valem para Coluna::pertenceA().');
        }

        $normalizada = strtoupper(trim($acao));

        if (!in_array($normalizada, self::ACOES, true)) {
            throw new EloException("Ação \"{$acao}\" inválida. Use: " . implode(', ', self::ACOES) . '.');
        }

        return $normalizada;
    }
}
