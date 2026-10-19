<?php

declare(strict_types=1);

namespace Elo\Console;

use Elo\Conexao;
use Haki\Console\Output;
use Haki\Support\Env;
use Throwable;

/**
 * CLI do EloPHP: php vendor/bin/elo <comando>
 */
final class Cli
{
    public function __construct(
        private readonly string $raiz,
        private readonly Output $saida = new Output(),
    ) {
    }

    /**
     * @param list<string> $argv argumentos do terminal (sem o nome do script)
     */
    public function executar(array $argv): int
    {
        $comando = $argv[0] ?? 'ajuda';

        if (in_array($comando, ['ajuda', 'help', '--help', '-h'], true)) {
            $this->ajuda();

            return 0;
        }

        Env::load($this->raiz . DIRECTORY_SEPARATOR . '.env');
        Conexao::raizDoProjeto($this->raiz);

        $executor = new ExecutorDeMigracoes(
            $this->raiz . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migracoes',
        );

        try {
            return match ($comando) {
                'migrar' => $this->migrar($executor),
                'migrar:reverter' => $this->reverter($executor),
                'migrar:status' => $this->status($executor),
                default => $this->naoEncontrado($comando),
            };
        } catch (Throwable $erro) {
            $this->saida->error($erro->getMessage());

            return 1;
        }
    }

    private function migrar(ExecutorDeMigracoes $executor): int
    {
        $aplicadas = $executor->migrar();

        if ($aplicadas === []) {
            $this->saida->success('Nenhuma migração pendente.');

            return 0;
        }

        foreach ($aplicadas as $nome) {
            $this->saida->success("Migrada: {$nome}");
        }

        return 0;
    }

    private function reverter(ExecutorDeMigracoes $executor): int
    {
        $desfeitas = $executor->reverter();

        if ($desfeitas === []) {
            $this->saida->info('Nenhuma migração para reverter.');

            return 0;
        }

        foreach ($desfeitas as $nome) {
            $this->saida->success("Revertida: {$nome}");
        }

        return 0;
    }

    private function status(ExecutorDeMigracoes $executor): int
    {
        foreach ($executor->status() as $item) {
            $situacao = $item['aplicada'] ? $this->saida->muted('aplicada') : 'pendente';
            $this->saida->line("  {$item['nome']}  {$situacao}");
        }

        return 0;
    }

    private function naoEncontrado(string $comando): int
    {
        $this->saida->error("Comando \"{$comando}\" não existe.");
        $this->ajuda();

        return 1;
    }

    private function ajuda(): void
    {
        $this->saida->line('Uso: php vendor/bin/elo <comando>');
        $this->saida->line();
        $this->saida->line('  migrar            Aplica as migrações pendentes (database/migracoes)');
        $this->saida->line('  migrar:reverter   Desfaz o último lote de migrações');
        $this->saida->line('  migrar:status     Lista as migrações e se já foram aplicadas');
    }
}
