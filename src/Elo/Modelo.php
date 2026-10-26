<?php

declare(strict_types=1);

namespace Elo;

use BadMethodCallException;
use Elo\Excecoes\EloException;
use Elo\Relacionamentos\Relacao;
use Elo\Suporte\Pluralizador;
use JsonSerializable;
use ReflectionClass;

/**
 * Active Record do EloPHP. Um model é uma classe que estende esta:
 *
 *     class Usuario extends \Elo\Modelo
 *     {
 *         protected array $pertenceA = ['setor' => Setor::class];
 *     }
 *
 * Tudo é aberto por padrão: preencher em massa, datas automáticas, dirty checking.
 * A única coluna protegida é a chave primária ($protegidos = ['id']).
 */
class Modelo implements JsonSerializable
{
    /** Nome da tabela. Se vazio, é deduzido do nome da classe (Usuario -> usuarios). */
    protected ?string $tabela = null;

    protected string $chavePrimaria = 'id';

    /** @var list<string> colunas que não entram no preenchimento em massa */
    protected array $protegidos = ['id'];

    /** @var list<string> colunas que não aparecem no JSON */
    protected array $ocultos = [];

    /** Preenche criado_em e atualizado_em automaticamente. */
    protected bool $datas = true;

    /** Usa excluido_em no lugar do DELETE e esconde os excluídos das consultas. */
    protected bool $exclusaoLogica = false;

    /** @var array<string, string|array<int|string, mixed>> */
    protected array $temUm = [];

    /** @var array<string, string|array<int|string, mixed>> */
    protected array $temMuitos = [];

    /** @var array<string, string|array<int|string, mixed>> */
    protected array $pertenceA = [];

    /** @var array<string, string|array<int|string, mixed>> */
    protected array $pertenceAMuitos = [];

    /** @var array<string, mixed> */
    private array $atributos = [];

    /** @var array<string, mixed> valores como estavam no banco (base do dirty checking) */
    private array $originais = [];

    private bool $existe = false;

    /** @var array<string, mixed> */
    private array $relacoesCarregadas = [];

    /**
     * @param array<string, mixed> $dados
     */
    public function __construct(array $dados = [])
    {
        $this->preencher($dados);
    }

    // ----------------------------------------------------- atalhos estáticos

    /** Instancia, preenche, salva e devolve o objeto em uma chamada. */
    public static function criar(array $dados): static
    {
        $modelo = new static($dados);
        $modelo->salvar();

        return $modelo;
    }

    public static function buscar(mixed $id): ?static
    {
        return static::buscarPor((new static())->nomeChavePrimaria(), $id);
    }

    public static function buscarPor(string $coluna, mixed $valor): ?static
    {
        $encontrado = static::consulta()->onde($coluna, '=', $valor)->primeiro();

        return $encontrado instanceof static ? $encontrado : null;
    }

    /**
     * @param array<string, mixed> $filtros igualdade por coluna
     * @return list<static>
     */
    public static function buscarTodos(array $filtros = []): array
    {
        $consulta = static::consulta();

        foreach ($filtros as $coluna => $valor) {
            $valor === null
                ? $consulta->ondeNulo((string) $coluna)
                : $consulta->onde((string) $coluna, '=', $valor);
        }

        return $consulta->obter();
    }

    /** Consulta fluente deste model. Atalho: Usuario::onde(...) faz o mesmo. */
    public static function consulta(): Construtor
    {
        return Construtor::para(static::class);
    }

    /** Qualquer método do Construtor pode ser chamado direto no model: Usuario::onde(...)->obter(). */
    public static function __callStatic(string $metodo, array $argumentos): mixed
    {
        return static::consulta()->{$metodo}(...$argumentos);
    }

    // ------------------------------------------------------------ persistência

    /**
     * Preenche atributos em massa. Ignora as colunas em $protegidos.
     *
     * @param array<string, mixed> $dados
     */
    public function preencher(array $dados): static
    {
        foreach ($dados as $campo => $valor) {
            if (!is_string($campo) || in_array($campo, $this->protegidos, true)) {
                continue;
            }

            $this->atributos[$campo] = $valor;
        }

        return $this;
    }

    /**
     * Insere (se for novo) ou atualiza (se já existe). No UPDATE, grava só o que mudou.
     */
    public function salvar(): bool
    {
        $gramatica = Conexao::gramatica();
        $tabela = $this->nomeTabela();
        $chave = $this->chavePrimaria;

        if (!$this->existe) {
            if ($this->datas) {
                $agora = self::agora();
                $this->atributos['criado_em'] ??= $agora;
                $this->atributos['atualizado_em'] ??= $agora;
            }

            $sql = $gramatica->compilarInsercao($tabela, array_keys($this->atributos), $chave);
            $stmt = Conexao::executar($sql, array_values($this->atributos));

            $id = $gramatica->retornaIdNaInsercao() ? $stmt->fetchColumn() : Conexao::obter()->lastInsertId();

            if (!isset($this->atributos[$chave]) && is_numeric($id) && (int) $id > 0) {
                $this->atributos[$chave] = (int) $id;
            }

            $this->originais = $this->atributos;
            $this->existe = true;

            return true;
        }

        if (!isset($this->originais[$chave])) {
            throw new EloException("Não é possível atualizar {$this->nomeTabela()} sem a chave primária \"{$chave}\".");
        }

        // Dirty checking: só entra no UPDATE o que mudou desde a última leitura ou gravação.
        $alterados = array_diff_assoc($this->atributos, $this->originais);
        unset($alterados[$chave]);

        if ($alterados === []) {
            return true;
        }

        if ($this->datas) {
            $alterados['atualizado_em'] = self::agora();
            $this->atributos['atualizado_em'] = $alterados['atualizado_em'];
        }

        $colunas = [];

        foreach (array_keys($alterados) as $coluna) {
            $colunas[] = $gramatica->identificador($coluna) . ' = ?';
        }

        $sql = 'UPDATE ' . $gramatica->identificador($tabela)
            . ' SET ' . implode(', ', $colunas)
            . ' WHERE ' . $gramatica->identificador($chave) . ' = ?';

        Conexao::executar($sql, [...array_values($alterados), $this->originais[$chave]]);

        $this->originais = $this->atributos;

        return true;
    }

    /**
     * Exclui o registro. Com exclusão lógica, grava excluido_em em vez de apagar.
     */
    public function excluir(): bool
    {
        if (!$this->existe) {
            throw new EloException('Este registro ainda não foi salvo, então não há o que excluir.');
        }

        $gramatica = Conexao::gramatica();
        $tabela = $gramatica->identificador($this->nomeTabela());
        $chave = $gramatica->identificador($this->chavePrimaria);
        $id = $this->originais[$this->chavePrimaria] ?? $this->atributos[$this->chavePrimaria] ?? null;

        if ($this->exclusaoLogica) {
            $agora = self::agora();

            Conexao::executar(
                "UPDATE {$tabela} SET " . $gramatica->identificador('excluido_em') . " = ? WHERE {$chave} = ?",
                [$agora, $id],
            );

            $this->atributos['excluido_em'] = $agora;
            $this->originais['excluido_em'] = $agora;

            return true;
        }

        Conexao::executar("DELETE FROM {$tabela} WHERE {$chave} = ?", [$id]);
        $this->existe = false;

        return true;
    }

    /** true depois de salvar ou de ser carregado do banco. */
    public function estaSalvo(): bool
    {
        return $this->existe;
    }

    // ------------------------------------------------------------ configuração

    public function nomeTabela(): string
    {
        if ($this->tabela !== null && $this->tabela !== '') {
            return $this->tabela;
        }

        return Pluralizador::tabelaDe((new ReflectionClass($this))->getShortName());
    }

    public function nomeChavePrimaria(): string
    {
        return $this->chavePrimaria;
    }

    public function usaDatas(): bool
    {
        return $this->datas;
    }

    public function usaExclusaoLogica(): bool
    {
        return $this->exclusaoLogica;
    }

    /**
     * Definição de uma relação declarada na model, ou null se não existir.
     *
     * @return array{tipo: string, definicao: string|array<int|string, mixed>}|null
     */
    public function definicaoRelacao(string $nome): ?array
    {
        $mapas = [
            'temUm' => $this->temUm,
            'temMuitos' => $this->temMuitos,
            'pertenceA' => $this->pertenceA,
            'pertenceAMuitos' => $this->pertenceAMuitos,
        ];

        foreach ($mapas as $tipo => $mapa) {
            if (array_key_exists($nome, $mapa)) {
                return ['tipo' => $tipo, 'definicao' => $mapa[$nome]];
            }
        }

        return null;
    }

    // ------------------------------------------------------- uso interno

    /**
     * Cria um model a partir de uma linha do banco. Usado pelo Construtor.
     *
     * @internal
     * @param array<string, mixed> $linha
     */
    public static function hidratar(array $linha): static
    {
        $modelo = new static();
        $chave = $modelo->chavePrimaria;

        // Algumas conexões devolvem o id como texto; aqui ele vira inteiro.
        if (isset($linha[$chave]) && is_string($linha[$chave]) && ctype_digit($linha[$chave])) {
            $linha[$chave] = (int) $linha[$chave];
        }

        $modelo->atributos = $linha;
        $modelo->originais = $linha;
        $modelo->existe = true;

        return $modelo;
    }

    /** @internal */
    public function definirRelacao(string $nome, mixed $valor): void
    {
        $this->relacoesCarregadas[$nome] = $valor;
    }

    /** @internal */
    public function removerAtributo(string $nome): void
    {
        unset($this->atributos[$nome], $this->originais[$nome]);
    }

    // ------------------------------------------------------------ acesso mágico

    /**
     * Sem parênteses, relação devolve o dado já carregado (e guarda em memória).
     */
    public function __get(string $nome): mixed
    {
        if (array_key_exists($nome, $this->atributos)) {
            return $this->atributos[$nome];
        }

        if ($this->definicaoRelacao($nome) === null) {
            return null;
        }

        if (!array_key_exists($nome, $this->relacoesCarregadas)) {
            $this->relacoesCarregadas[$nome] = $this->relacao($nome)->obter();
        }

        return $this->relacoesCarregadas[$nome];
    }

    public function __set(string $nome, mixed $valor): void
    {
        $this->atributos[$nome] = $valor;
    }

    public function __isset(string $nome): bool
    {
        return isset($this->atributos[$nome]);
    }

    /**
     * Com parênteses, relação devolve o motor dela, para encadear filtros ou vínculos.
     */
    public function __call(string $metodo, array $argumentos): mixed
    {
        if ($this->definicaoRelacao($metodo) !== null) {
            return $this->relacao($metodo);
        }

        throw new BadMethodCallException("O método ou relação \"{$metodo}\" não existe em " . static::class . '.');
    }

    // ------------------------------------------------------------ JSON / array

    /**
     * Atributos (sem os ocultos) e relações já carregadas.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $dados = array_diff_key($this->atributos, array_flip($this->ocultos));

        foreach ($this->relacoesCarregadas as $nome => $valor) {
            $dados[$nome] = self::paraArray($valor);
        }

        return $dados;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function relacao(string $nome): Relacao
    {
        $definicao = $this->definicaoRelacao($nome);

        if ($definicao === null) {
            throw new EloException("A relação \"{$nome}\" não existe em " . static::class . '.');
        }

        return Relacao::paraTipo($definicao['tipo'], $this, $nome, $definicao['definicao']);
    }

    private static function paraArray(mixed $valor): mixed
    {
        if ($valor instanceof self) {
            return $valor->toArray();
        }

        if (is_array($valor)) {
            return array_map(static fn (mixed $item): mixed => self::paraArray($item), $valor);
        }

        return $valor;
    }

    private static function agora(): string
    {
        return date('Y-m-d H:i:s');
    }
}
