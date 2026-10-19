<?php

declare(strict_types=1);

namespace Elo\Relacionamentos;

use Elo\Construtor;
use Elo\Conexao;
use Elo\Excecoes\EloException;
use PDO;

/**
 * $pertenceAMuitos: tabela pivô. Ex.: Usuario pertenceAMuitos Cargo.
 *
 * Convenções (sobrescrevíveis com 'pivo', 'chave_pai' e 'chave_alvo'):
 *   - pivô: nomes singulares em ordem alfabética -> cargo_usuario
 *   - colunas: usuario_id e cargo_id
 *
 * Métodos de vínculo: anexar(), desanexar(), sincronizar().
 */
final class RelacaoMuitosParaMuitos extends Relacao
{
    /**
     * @return list<Modelo>
     */
    public function obter(): array
    {
        if ($this->idDoPai() === null) {
            return [];
        }

        return $this->consulta()->obter();
    }

    /**
     * Adiciona vínculos que ainda não existem. Repetidos são ignorados.
     *
     * @return int quantos vínculos novos foram criados
     */
    public function anexar(int|array $ids): int
    {
        $this->exigirPaiSalvo();

        $novos = array_values(array_diff($this->normalizarIds($ids), $this->idsAnexados()));
        $this->inserirVinculos($novos);

        return count($novos);
    }

    /**
     * Remove vínculos específicos, ou todos se $ids for null.
     *
     * @return int quantos vínculos foram removidos
     */
    public function desanexar(int|array|null $ids = null): int
    {
        $this->exigirPaiSalvo();

        $pivo = $this->pivo();
        $gramatica = Conexao::gramatica();

        $sql = 'DELETE FROM ' . $gramatica->identificador($pivo['pivo'])
            . ' WHERE ' . $gramatica->identificador($pivo['chave_pai']) . ' = ?';
        $valores = [$this->idDoPai()];

        if ($ids !== null) {
            $lista = $this->normalizarIds($ids);

            if ($lista === []) {
                return 0;
            }

            $marcas = implode(', ', array_fill(0, count($lista), '?'));
            $sql .= ' AND ' . $gramatica->identificador($pivo['chave_alvo']) . " IN ({$marcas})";
            array_push($valores, ...$lista);
        }

        return Conexao::executar($sql, $valores)->rowCount();
    }

    /**
     * Deixa na tabela pivô exatamente os ids informados: adiciona os que faltam e remove os que sobram.
     *
     * @param list<int|string> $ids
     * @return array{anexados: list<int>, desanexados: list<int>}
     */
    public function sincronizar(array $ids): array
    {
        $this->exigirPaiSalvo();

        $novos = $this->normalizarIds($ids);
        $atuais = $this->idsAnexados();

        $adicionar = array_values(array_diff($novos, $atuais));
        $remover = array_values(array_diff($atuais, $novos));

        if ($remover !== []) {
            $this->desanexar($remover);
        }

        $this->inserirVinculos($adicionar);

        return ['anexados' => $adicionar, 'desanexados' => $remover];
    }

    protected function montarConsulta(): Construtor
    {
        $pivo = $this->pivo();
        $alvo = $this->alvo();

        $consulta = Construtor::para($this->classeAlvo)->juntar(
            $pivo['pivo'],
            $alvo->nomeTabela() . '.' . $alvo->nomeChavePrimaria(),
            $pivo['pivo'] . '.' . $pivo['chave_alvo'],
        );

        return $this->filtrarPorPai($consulta, $pivo['pivo'] . '.' . $pivo['chave_pai']);
    }

    /**
     * Nomes da tabela pivô e das colunas, com as convenções ou as opções informadas.
     *
     * @param array<string, mixed> $opcoes
     * @return array{pivo: string, chave_pai: string, chave_alvo: string}
     */
    public static function resolverPivo(string $classePai, string $classeAlvo, array $opcoes): array
    {
        $nomePai = \Elo\Suporte\Pluralizador::snake(Relacao::nomeCurto($classePai));
        $nomeAlvo = \Elo\Suporte\Pluralizador::snake(Relacao::nomeCurto($classeAlvo));

        $partes = [$nomePai, $nomeAlvo];
        sort($partes);

        return [
            'pivo' => (string) ($opcoes['pivo'] ?? implode('_', $partes)),
            'chave_pai' => (string) ($opcoes['chave_pai'] ?? $nomePai . '_id'),
            'chave_alvo' => (string) ($opcoes['chave_alvo'] ?? $nomeAlvo . '_id'),
        ];
    }

    /**
     * @return array{pivo: string, chave_pai: string, chave_alvo: string}
     */
    private function pivo(): array
    {
        return self::resolverPivo($this->pai::class, $this->classeAlvo, $this->opcoes);
    }

    /**
     * @return list<int>
     */
    private function idsAnexados(): array
    {
        $pivo = $this->pivo();
        $gramatica = Conexao::gramatica();

        $sql = 'SELECT ' . $gramatica->identificador($pivo['chave_alvo'])
            . ' FROM ' . $gramatica->identificador($pivo['pivo'])
            . ' WHERE ' . $gramatica->identificador($pivo['chave_pai']) . ' = ?';

        return array_map('intval', Conexao::executar($sql, [$this->idDoPai()])->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $ids
     */
    private function inserirVinculos(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $pivo = $this->pivo();
        $gramatica = Conexao::gramatica();

        $sql = 'INSERT INTO ' . $gramatica->identificador($pivo['pivo'])
            . ' (' . $gramatica->identificador($pivo['chave_pai']) . ', ' . $gramatica->identificador($pivo['chave_alvo']) . ')'
            . ' VALUES (?, ?)';

        foreach ($ids as $id) {
            Conexao::executar($sql, [$this->idDoPai(), $id]);
        }
    }

    private function exigirPaiSalvo(): void
    {
        if ($this->idDoPai() === null) {
            throw new EloException('Salve o modelo pai com salvar() antes de mexer nos vínculos.');
        }
    }

    /**
     * @param int|list<int|string> $ids
     * @return list<int>
     */
    private function normalizarIds(int|array $ids): array
    {
        $lista = is_array($ids) ? $ids : [$ids];
        $saida = [];

        foreach ($lista as $id) {
            if (!is_numeric($id) || (int) $id != $id) {
                throw new EloException('Os ids dos relacionamentos precisam ser números inteiros.');
            }

            $saida[] = (int) $id;
        }

        return array_values(array_unique($saida));
    }
}
