<?php

declare(strict_types=1);

namespace Haki\Auth\Tentativas;

use PDO;
use PDOException;

/**
 * Contadores de falhas de login em qualquer banco que o PDO atenda.
 *
 * Com vários servidores, use um banco compartilhado (o mesmo do PdoTokenStore).
 */
final class PdoArmazenamentoDeTentativas implements ArmazenamentoDeTentativas
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cria a tabela haki_tentativas_login. Pode ser chamado mais de uma vez.
     */
    public function criarTabela(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS haki_tentativas_login (
                chave       VARCHAR(255) NOT NULL,
                ocorrido_em BIGINT NOT NULL
            )',
        );

        try {
            $this->pdo->exec('CREATE INDEX haki_tentativas_chave ON haki_tentativas_login (chave, ocorrido_em)');
        } catch (PDOException) {
            // Índice já existe.
        }
    }

    public function registrar(string $chave, int $agora): void
    {
        $this->run('INSERT INTO haki_tentativas_login (chave, ocorrido_em) VALUES (?, ?)', [$chave, $agora]);
    }

    public function falhas(string $chave, int $agora, int $janela): int
    {
        $statement = $this->run(
            'SELECT COUNT(*) FROM haki_tentativas_login WHERE chave = ? AND ocorrido_em > ?',
            [$chave, $agora - $janela],
        );

        return (int) $statement->fetchColumn();
    }

    public function limpar(string $chave): void
    {
        $this->run('DELETE FROM haki_tentativas_login WHERE chave = ?', [$chave]);
    }

    public function removerVencidas(int $agora, int $janela): int
    {
        return $this->run('DELETE FROM haki_tentativas_login WHERE ocorrido_em <= ?', [$agora - $janela])->rowCount();
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
