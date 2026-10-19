<?php

declare(strict_types=1);

namespace Elo\Console;

use Elo\Coluna;
use Elo\Conexao;
use Elo\Excecoes\EloException;
use Elo\Migracao;
use Elo\Tabela;
use PDO;
use Throwable;

/**
 * Aplica e desfaz migrações da pasta database/migracoes.
 *
 * A tabela "migracoes" guarda o que já foi aplicado e em qual lote.
 * "migrar" aplica tudo o que está pendente em um novo lote;
 * "migrar:reverter" desfaz o último lote, na ordem inversa.
 */
final class ExecutorDeMigracoes
{
    private const TABELA = 'migracoes';

    public function __construct(private readonly string $pasta)
    {
    }

    /**
     * @return list<string> nomes das migrações aplicadas agora
     */
    public function migrar(): array
    {
        $this->garantirTabela();

        $pendentes = array_values(array_diff($this->arquivos(), $this->aplicadas()));

        if ($pendentes === []) {
            return [];
        }

        $lote = $this->ultimoLote() + 1;
        $feitas = [];

        foreach ($pendentes as $nome) {
            $migracao = $this->carregar($nome);

            try {
                $migracao->subir();
            } catch (Throwable $erro) {
                throw new EloException("Falha ao aplicar a migração {$nome}: " . $erro->getMessage(), 0, $erro);
            }

            Conexao::executar('INSERT INTO ' . self::TABELA . ' (migracao, lote) VALUES (?, ?)', [$nome, $lote]);
            $feitas[] = $nome;
        }

        return $feitas;
    }

    /**
     * @return list<string> nomes das migrações desfeitas
     */
    public function reverter(): array
    {
        $this->garantirTabela();

        $lote = $this->ultimoLote();

        if ($lote === 0) {
            return [];
        }

        $nomes = Conexao::executar(
            'SELECT migracao FROM ' . self::TABELA . ' WHERE lote = ? ORDER BY id DESC',
            [$lote],
        )->fetchAll(PDO::FETCH_COLUMN);

        $desfeitas = [];

        foreach ($nomes as $nome) {
            $nome = (string) $nome;
            $migracao = $this->carregar($nome);

            try {
                $migracao->descer();
            } catch (Throwable $erro) {
                throw new EloException("Falha ao reverter a migração {$nome}: " . $erro->getMessage(), 0, $erro);
            }

            Conexao::executar('DELETE FROM ' . self::TABELA . ' WHERE migracao = ?', [$nome]);
            $desfeitas[] = $nome;
        }

        return $desfeitas;
    }

    /**
     * @return list<array{nome: string, aplicada: bool}>
     */
    public function status(): array
    {
        $this->garantirTabela();

        $aplicadas = $this->aplicadas();

        return array_map(
            static fn (string $nome): array => ['nome' => $nome, 'aplicada' => in_array($nome, $aplicadas, true)],
            $this->arquivos(),
        );
    }

    private function garantirTabela(): void
    {
        if (Tabela::existe(self::TABELA)) {
            return;
        }

        Tabela::criar(self::TABELA, [
            'migracao' => Coluna::texto(255)->unico(),
            'lote' => Coluna::inteiro(),
            'executada_em' => Coluna::timestamp()->padraoAtual(),
        ], ['sem_datas' => true]);
    }

    /**
     * @return list<string> nomes (sem .php), em ordem alfabética, que é a ordem de criação
     */
    private function arquivos(): array
    {
        if (!is_dir($this->pasta)) {
            return [];
        }

        $caminhos = glob(rtrim($this->pasta, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($caminhos);

        return array_map(static fn (string $caminho): string => basename($caminho, '.php'), $caminhos);
    }

    /**
     * @return list<string>
     */
    private function aplicadas(): array
    {
        $linhas = Conexao::executar('SELECT migracao FROM ' . self::TABELA . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $linhas);
    }

    private function ultimoLote(): int
    {
        return (int) Conexao::executar('SELECT MAX(lote) FROM ' . self::TABELA)->fetchColumn();
    }

    private function carregar(string $nome): Migracao
    {
        $arquivo = rtrim($this->pasta, '/\\') . DIRECTORY_SEPARATOR . $nome . '.php';

        if (!is_file($arquivo)) {
            throw new EloException("Arquivo da migração não encontrado: {$arquivo}");
        }

        $migracao = require $arquivo;

        if (!$migracao instanceof Migracao) {
            throw new EloException("{$nome}.php precisa retornar um objeto que estenda Elo\\Migracao (use: return new class extends Migracao { ... };).");
        }

        return $migracao;
    }
}
