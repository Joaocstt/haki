<?php

declare(strict_types=1);

namespace Elo\Gramaticas;

use Elo\Coluna;
use Elo\Excecoes\EloException;

/**
 * Parte comum a todos os bancos. Cada banco sobrescreve só o que é diferente.
 */
abstract class GramaticaBase implements ContratoGramatica
{
    public function identificador(string $nome): string
    {
        $partes = explode('.', $nome);
        $saida = [];

        foreach ($partes as $indice => $parte) {
            // "tabela.*" só é aceito como último pedaço (usado em selecionar()).
            if ($parte === '*' && $indice > 0 && $indice === count($partes) - 1) {
                $saida[] = '*';
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parte) !== 1) {
                throw new EloException("Nome inválido \"{$nome}\": use apenas letras, números e _, começando por letra ou _.");
            }

            $saida[] = $this->envolver($parte);
        }

        return implode('.', $saida);
    }

    public function compilarCriarTabela(string $tabela, array $colunas): array
    {
        $definicoes = [];
        $restricoes = [];
        $indices = [];

        foreach ($colunas as $nome => $coluna) {
            if ($coluna->tipo === 'id') {
                $definicoes[] = $this->identificador($nome) . ' ' . $this->tipoChavePrimaria();
                continue;
            }

            $definicoes[] = $this->definicaoColuna($nome, $coluna);

            if ($coluna->referencia !== null) {
                $restricoes[] = $this->restricaoReferencia($tabela, $nome, $coluna);
            }

            if ($coluna->indice && !$coluna->unico) {
                $indices[] = $this->indice($tabela, $nome);
            }
        }

        $sql = 'CREATE TABLE ' . $this->identificador($tabela) . ' ('
            . implode(', ', array_merge($definicoes, $restricoes)) . ')';

        return array_merge([$sql], $indices);
    }

    public function compilarAlterarTabela(string $tabela, array $adicionar, array $remover): array
    {
        $comandos = [];
        $alvo = $this->identificador($tabela);

        foreach ($adicionar as $nome => $coluna) {
            $comandos[] = "ALTER TABLE {$alvo} " . $this->prefixoAdicionarColuna() . ' ' . $this->definicaoColuna($nome, $coluna);

            if ($coluna->referencia !== null) {
                $comandos[] = "ALTER TABLE {$alvo} ADD " . $this->restricaoReferencia($tabela, $nome, $coluna);
            }

            if ($coluna->indice && !$coluna->unico) {
                $comandos[] = $this->indice($tabela, $nome);
            }
        }

        foreach ($remover as $nome) {
            $comandos[] = "ALTER TABLE {$alvo} DROP COLUMN " . $this->identificador($nome);
        }

        return $comandos;
    }

    public function compilarApagarTabela(string $tabela): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->identificador($tabela);
    }

    public function compilarInsercao(string $tabela, array $colunas, string $pk): string
    {
        $partes = ['INSERT INTO ' . $this->identificador($tabela)];

        if ($colunas !== []) {
            $partes[] = '(' . implode(', ', array_map([$this, 'identificador'], $colunas)) . ')';
        }

        $saida = $this->clausulaSaida($pk);

        if ($saida !== '') {
            $partes[] = $saida;
        }

        $partes[] = $colunas === []
            ? $this->valoresVazios()
            : 'VALUES (' . implode(', ', array_fill(0, count($colunas), '?')) . ')';

        $retorno = $this->clausulaRetorno($pk);

        if ($retorno !== '') {
            $partes[] = $retorno;
        }

        return implode(' ', $partes);
    }

    public function paginacao(?int $limite, ?int $pular): string
    {
        if ($limite === null && $pular === null) {
            return '';
        }

        $partes = [];

        if ($limite !== null) {
            $partes[] = "LIMIT {$limite}";
        }

        if ($pular !== null) {
            $partes[] = "OFFSET {$pular}";
        }

        return implode(' ', $partes);
    }

    public function retornaIdNaInsercao(): bool
    {
        return false;
    }

    public function exigeOrdenacaoParaPaginar(): bool
    {
        return false;
    }

    /** Coloca o nome entre as aspas do banco (sem validar; quem chama valida). */
    abstract protected function envolver(string $nome): string;

    /** Tipo SQL da chave primária automática, já com PRIMARY KEY e autoincremento. */
    abstract protected function tipoChavePrimaria(): string;

    /** Tipo SQL de uma coluna (sem NULL, DEFAULT ou UNIQUE, que são acrescentados depois). */
    abstract protected function tipoColuna(Coluna $coluna): string;

    protected function booleano(bool $valor): string
    {
        return $valor ? '1' : '0';
    }

    /** Bancos com ENUM nativo (MySQL) não precisam de CHECK. */
    protected function enumNativo(): bool
    {
        return false;
    }

    /** Bancos com UNSIGNED nativo (MySQL) não precisam de CHECK para semSinal(). */
    protected function inteiroSemSinalNativo(): bool
    {
        return false;
    }

    protected function acaoReferencia(string $acao): string
    {
        return $acao;
    }

    /** Cláusula colocada depois da lista de colunas (OUTPUT no SQL Server). */
    protected function clausulaSaida(string $pk): string
    {
        return '';
    }

    /** Cláusula colocada no fim do INSERT (RETURNING no PostgreSQL). */
    protected function clausulaRetorno(string $pk): string
    {
        return '';
    }

    /** INSERT sem nenhuma coluna informada. */
    protected function valoresVazios(): string
    {
        return 'DEFAULT VALUES';
    }

    protected function prefixoAdicionarColuna(): string
    {
        return 'ADD COLUMN';
    }

    protected function escaparTexto(string $texto): string
    {
        return str_replace("'", "''", $texto);
    }

    /** Tipo para opcoes() quando o banco não tem ENUM. */
    protected function tipoOpcoes(Coluna $coluna): string
    {
        $maior = 1;

        foreach ($coluna->opcoes as $opcao) {
            $maior = max($maior, strlen($opcao));
        }

        return "VARCHAR({$maior})";
    }

    protected function definicaoColuna(string $nome, Coluna $coluna): string
    {
        if ($coluna->tipo === 'id') {
            throw new EloException("A coluna \"{$nome}\" é a chave primária. Ela não pode ser alterada com Tabela::alterar().");
        }

        $campo = $this->identificador($nome);
        $sql = $campo . ' ' . $this->tipoColuna($coluna) . ($coluna->nulo ? ' NULL' : ' NOT NULL');

        if ($coluna->padraoAtual) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($coluna->temPadrao) {
            $sql .= ' DEFAULT ' . $this->valorPadrao($coluna->padrao);
        }

        if ($coluna->tipo === 'opcoes' && !$this->enumNativo()) {
            $lista = implode(', ', array_map(fn (string $o) => "'" . $this->escaparTexto($o) . "'", $coluna->opcoes));
            $sql .= " CHECK ({$campo} IN ({$lista}))";
        }

        if ($coluna->semSinal && !$this->inteiroSemSinalNativo()) {
            $sql .= " CHECK ({$campo} >= 0)";
        }

        if ($coluna->unico) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    protected function valorPadrao(mixed $valor): string
    {
        return match (true) {
            $valor === null => 'NULL',
            is_bool($valor) => $this->booleano($valor),
            is_int($valor), is_float($valor) => (string) $valor,
            default => "'" . $this->escaparTexto((string) $valor) . "'",
        };
    }

    protected function restricaoReferencia(string $tabela, string $campo, Coluna $coluna): string
    {
        $ref = $coluna->referencia ?? [];

        $sql = 'CONSTRAINT ' . $this->identificador($this->nomeDeRestricao('fk', $tabela, $campo))
            . ' FOREIGN KEY (' . $this->identificador($campo) . ')'
            . ' REFERENCES ' . $this->identificador((string) ($ref['tabela'] ?? ''))
            . ' (' . $this->identificador((string) ($ref['coluna'] ?? 'id')) . ')';

        if ($coluna->aoExcluir !== null) {
            $sql .= ' ON DELETE ' . $this->acaoReferencia($coluna->aoExcluir);
        }

        if ($coluna->aoAtualizar !== null) {
            $sql .= ' ON UPDATE ' . $this->acaoReferencia($coluna->aoAtualizar);
        }

        return $sql;
    }

    protected function indice(string $tabela, string $campo): string
    {
        return 'CREATE INDEX ' . $this->identificador($this->nomeDeRestricao('ix', $tabela, $campo))
            . ' ON ' . $this->identificador($tabela) . ' (' . $this->identificador($campo) . ')';
    }

    /**
     * Nome de índice ou restrição. Nomes longos são encurtados com um hash, para caber
     * no limite de 64 caracteres do MySQL.
     */
    protected function nomeDeRestricao(string ...$partes): string
    {
        $nome = implode('_', $partes);

        return strlen($nome) <= 60 ? $nome : substr($nome, 0, 50) . '_' . substr(md5($nome), 0, 8);
    }
}
