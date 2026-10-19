<?php

declare(strict_types=1);

namespace Elo;

use Elo\Excecoes\EloException;
use Elo\Gramaticas\ContratoGramatica;
use Elo\Relacionamentos\CarregadorDeRelacoes;
use Elo\Suporte\Paginador;

/**
 * Consulta fluente. Obtida pelos models: Usuario::onde(...), Usuario::com(...).
 *
 * Todo valor vai como parâmetro (?) do PDO. Nomes de colunas e tabelas são validados e
 * colocados entre aspas pela gramática do banco. Não existe SQL colado do usuário.
 */
final class Construtor
{
    private const OPERADORES = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var list<string> */
    private array $colunas = [];

    /** @var list<array{0: string, 1: string, 2: list<mixed>}> conector, SQL, valores */
    private array $condicoes = [];

    /** @var list<string> */
    private array $ordens = [];

    /** @var list<string> */
    private array $juncoes = [];

    /** @var list<string> */
    private array $relacoes = [];

    private ?int $limiteAtual = null;
    private ?int $puloAtual = null;
    private bool $incluirExcluidos = false;

    private function __construct(
        private readonly string $tabela,
        private readonly ?string $classe,
        private readonly bool $exclusaoLogica,
    ) {
    }

    /**
     * @param class-string<Modelo> $classe
     */
    public static function para(string $classe): self
    {
        $modelo = new $classe();

        return new self($modelo->nomeTabela(), $classe, $modelo->usaExclusaoLogica());
    }

    // ------------------------------------------------------------- seleção

    /**
     * Colunas do SELECT. Aceita "coluna", "tabela.coluna" e "coluna as apelido".
     */
    public function selecionar(string ...$colunas): self
    {
        foreach ($colunas as $coluna) {
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', trim($coluna), $partes) === 1) {
                $this->colunas[] = $this->identificar($partes[1]) . ' AS ' . $this->identificar($partes[2]);
            } else {
                $this->colunas[] = $this->identificar($coluna);
            }
        }

        return $this;
    }

    // ------------------------------------------------------------- filtros

    /**
     * onde('status', 'ABERTO') ou onde('ano', '>=', 2025). Todos os condições usam AND.
     */
    public function onde(string $coluna, mixed $operador, mixed $valor = null): self
    {
        if (func_num_args() === 2) {
            $valor = $operador;
            $operador = '=';
        }

        return $this->adicionarCondicao('AND', $coluna, $operador, $valor);
    }

    /** Mesma coisa que onde(), mas com OR. */
    public function ouOnde(string $coluna, mixed $operador, mixed $valor = null): self
    {
        if (func_num_args() === 2) {
            $valor = $operador;
            $operador = '=';
        }

        return $this->adicionarCondicao('OR', $coluna, $operador, $valor);
    }

    /** @param array{0: mixed, 1: mixed} $intervalo */
    public function ondeEntre(string $coluna, array $intervalo): self
    {
        $intervalo = array_values($intervalo);

        if (count($intervalo) !== 2) {
            throw new EloException('ondeEntre() precisa de exatamente dois valores: [min, max].');
        }

        $this->condicoes[] = ['AND', $this->identificar($coluna) . ' BETWEEN ? AND ?', $intervalo];

        return $this;
    }

    /**
     * @param list<mixed> $valores
     */
    public function ondeEm(string $coluna, array $valores): self
    {
        $valores = array_values($valores);

        if ($valores === []) {
            // Lista vazia significa "nenhum resultado", e não um IN () inválido.
            $this->condicoes[] = ['AND', '1 = 0', []];

            return $this;
        }

        $marcas = implode(', ', array_fill(0, count($valores), '?'));
        $this->condicoes[] = ['AND', $this->identificar($coluna) . " IN ({$marcas})", $valores];

        return $this;
    }

    public function ondeNulo(string $coluna): self
    {
        $this->condicoes[] = ['AND', $this->identificar($coluna) . ' IS NULL', []];

        return $this;
    }

    public function ondeNaoNulo(string $coluna): self
    {
        $this->condicoes[] = ['AND', $this->identificar($coluna) . ' IS NOT NULL', []];

        return $this;
    }

    /**
     * Junta outra tabela (INNER JOIN). Usado pelos relacionamentos muitos-para-muitos.
     */
    public function juntar(string $tabela, string $colunaEsquerda, string $colunaDireita): self
    {
        $this->juncoes[] = 'INNER JOIN ' . $this->identificar($tabela)
            . ' ON ' . $this->identificar($colunaEsquerda) . ' = ' . $this->identificar($colunaDireita);

        return $this;
    }

    // ----------------------------------------------------------- ordem etc.

    public function ordenarPor(string $coluna, string $direcao = 'ASC'): self
    {
        $direcao = strtoupper(trim($direcao));

        if (!in_array($direcao, ['ASC', 'DESC'], true)) {
            throw new EloException("Direção \"{$direcao}\" inválida em ordenarPor(). Use ASC ou DESC.");
        }

        $this->ordens[] = $this->identificar($coluna) . ' ' . $direcao;

        return $this;
    }

    public function limite(int $quantidade): self
    {
        if ($quantidade < 0) {
            throw new EloException('limite() não aceita número negativo.');
        }

        $this->limiteAtual = $quantidade;

        return $this;
    }

    public function pular(int $quantidade): self
    {
        if ($quantidade < 0) {
            throw new EloException('pular() não aceita número negativo.');
        }

        $this->puloAtual = $quantidade;

        return $this;
    }

    /**
     * Carrega relacionamentos em lote (eager loading): evita o problema de N+1 consultas.
     */
    public function com(string ...$relacoes): self
    {
        array_push($this->relacoes, ...$relacoes);

        return $this;
    }

    /**
     * Inclui registros com exclusão lógica (excluido_em preenchido).
     */
    public function comExcluidos(): self
    {
        $this->incluirExcluidos = true;

        return $this;
    }

    // ------------------------------------------------------------ execução

    /**
     * @return list<object|array<string, mixed>> models (ou arrays, se não houver model)
     */
    public function obter(): array
    {
        [$sql, $valores] = $this->compilar();

        $linhas = Conexao::executar($sql, $valores)->fetchAll();

        $itens = [];
        $classe = $this->classe;

        foreach ($linhas as $linha) {
            $itens[] = $classe === null ? $linha : $classe::hidratar($linha);
        }

        if ($classe !== null && $this->relacoes !== [] && $itens !== []) {
            CarregadorDeRelacoes::carregar($itens, $this->relacoes, $classe);
        }

        return $itens;
    }

    public function primeiro(): ?object
    {
        $copia = clone $this;
        $copia->limiteAtual = 1;

        return $copia->obter()[0] ?? null;
    }

    public function contar(): int
    {
        [$sql, $valores] = $this->compilar(true);

        return (int) Conexao::executar($sql, $valores)->fetchColumn();
    }

    public function existe(): bool
    {
        return $this->contar() > 0;
    }

    /**
     * Paginação completa: conta o total e busca só a página pedida.
     */
    public function paginar(int $porPagina = 15, int $pagina = 1): Paginador
    {
        if ($porPagina < 1 || $pagina < 1) {
            throw new EloException('paginar() precisa de número de página e de itens por página maiores que zero.');
        }

        $total = $this->contar();

        $copia = clone $this;
        $copia->limiteAtual = $porPagina;
        $copia->puloAtual = ($pagina - 1) * $porPagina;

        return new Paginador($copia->obter(), $total, $porPagina, $pagina);
    }

    /**
     * SQL e valores que a consulta vai usar. Útil para depurar.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function compilar(bool $contagem = false): array
    {
        $gramatica = Conexao::gramatica();
        $tabela = $gramatica->identificador($this->tabela);

        if ($contagem) {
            $selecao = 'COUNT(*) AS total';
        } elseif ($this->colunas !== []) {
            $selecao = implode(', ', $this->colunas);
        } else {
            $selecao = $tabela . '.*';
        }

        $sql = "SELECT {$selecao} FROM {$tabela}";

        foreach ($this->juncoes as $juncao) {
            $sql .= ' ' . $juncao;
        }

        [$filtro, $valores] = $this->filtros($gramatica, $tabela);

        if ($filtro !== '') {
            $sql .= ' WHERE ' . $filtro;
        }

        if ($contagem) {
            return [$sql, $valores];
        }

        if ($this->ordens !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->ordens);
        } elseif ($gramatica->exigeOrdenacaoParaPaginar() && ($this->limiteAtual !== null || $this->puloAtual !== null)) {
            $sql .= ' ORDER BY (SELECT NULL)';
        }

        $paginacao = $gramatica->paginacao($this->limiteAtual, $this->puloAtual);

        if ($paginacao !== '') {
            $sql .= ' ' . $paginacao;
        }

        return [$sql, $valores];
    }

    // ------------------------------------------------------------- privados

    private function adicionarCondicao(string $conector, string $coluna, mixed $operador, mixed $valor): self
    {
        $op = strtoupper(trim((string) $operador));

        if (!in_array($op, self::OPERADORES, true)) {
            throw new EloException("Operador \"{$operador}\" não suportado. Use: " . implode(', ', self::OPERADORES) . '.');
        }

        if ($valor === null) {
            throw new EloException("Para comparar \"{$coluna}\" com NULL, use ondeNulo() ou ondeNaoNulo().");
        }

        $this->condicoes[] = [$conector, $this->identificar($coluna) . " {$op} ?", [$valor]];

        return $this;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function filtros(ContratoGramatica $gramatica, string $tabela): array
    {
        $partes = [];
        $valores = [];

        foreach ($this->condicoes as $indice => [$conector, $sql, $vals]) {
            $partes[] = ($indice === 0 ? '' : $conector . ' ') . $sql;
            array_push($valores, ...$vals);
        }

        $usuario = implode(' ', $partes);
        $soft = $this->exclusaoLogica && !$this->incluirExcluidos;

        $where = [];

        if ($soft) {
            // Filtro global da exclusão lógica: esconde os excluídos sem que o usuário precise pedir.
            $where[] = $tabela . '.' . $gramatica->identificador('excluido_em') . ' IS NULL';
        }

        if ($usuario !== '') {
            // Entre parênteses para o OR da consulta não "vazar" para o filtro de exclusão.
            $where[] = $soft ? "({$usuario})" : $usuario;
        }

        return [implode(' AND ', $where), $valores];
    }

    private function identificar(string $nome): string
    {
        return Conexao::gramatica()->identificador($nome);
    }
}
