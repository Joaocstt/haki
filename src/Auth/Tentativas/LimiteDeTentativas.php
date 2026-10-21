<?php

declare(strict_types=1);

namespace Haki\Auth\Tentativas;

use Closure;
use Haki\Exceptions\AuthenticationException;
use Haki\Exceptions\TooManyAttemptsException;
use InvalidArgumentException;

/**
 * Protege o login contra tentativas repetidas (força bruta).
 *
 * Cada tentativa é associada a chaves, em geral uma por conta e outra por IP:
 *
 *     $limite->verificar(['email:' . $email, 'ip:' . $ip], function () use ($email, $senha) {
 *         $usuario = Usuario::buscarPor('email', $email);
 *
 *         return $usuario !== null && password_verify($senha, $usuario->senha) ? $usuario : null;
 *     });
 *
 * Quando as falhas passam do máximo dentro da janela, a tentativa nem é feita: responde 429.
 * A verificação devolve o resultado da função quando dá certo, e as falhas da chave são zeradas.
 */
final class LimiteDeTentativas
{
    /** Chance (em %) de limpar as falhas vencidas a cada falha registrada. */
    private const PURGE_CHANCE = 1;

    /** @var Closure(): int */
    private readonly Closure $relogio;

    /**
     * @param int $maximo falhas permitidas dentro da janela
     * @param int $janela tamanho da janela em segundos (também é o tempo de bloqueio)
     * @param (Closure(): int)|null $relogio relógio injetável, para testes
     */
    public function __construct(
        private readonly ArmazenamentoDeTentativas $armazenamento,
        private readonly int $maximo = 5,
        private readonly int $janela = 900,
        ?Closure $relogio = null,
    ) {
        if ($maximo < 1 || $janela < 1) {
            throw new InvalidArgumentException('O máximo de tentativas e a janela precisam ser maiores que zero.');
        }

        $this->relogio = $relogio ?? static fn (): int => time();
    }

    /**
     * Executa a tentativa se as chaves não estiverem bloqueadas.
     *
     * A função $tentativa deve devolver o resultado quando as credenciais estão certas,
     * e null ou false quando estão erradas. Também conta como falha se ela lançar AuthenticationException.
     *
     * @template T
     * @param list<string> $chaves ex.: ['email:ana@x.br', 'ip:203.0.113.5']
     * @param callable(): (T|null|false) $tentativa
     * @return T
     *
     * @throws TooManyAttemptsException quando alguma chave está bloqueada
     * @throws AuthenticationException quando as credenciais estão erradas
     */
    public function verificar(array $chaves, callable $tentativa): mixed
    {
        $chaves = $this->normalizar($chaves);
        $agora = ($this->relogio)();

        foreach ($chaves as $chave) {
            if ($this->armazenamento->falhas($chave, $agora, $this->janela) >= $this->maximo) {
                throw new TooManyAttemptsException($this->janela);
            }
        }

        try {
            $resultado = $tentativa();
        } catch (AuthenticationException $erro) {
            $this->registrarFalha($chaves, $agora);

            throw $erro;
        }

        if ($resultado === null || $resultado === false) {
            $this->registrarFalha($chaves, $agora);

            throw new AuthenticationException('Credenciais inválidas.', 'E-mail ou senha incorretos.');
        }

        foreach ($chaves as $chave) {
            $this->armazenamento->limpar($chave);
        }

        return $resultado;
    }

    /**
     * @param list<string> $chaves
     */
    private function registrarFalha(array $chaves, int $agora): void
    {
        foreach ($chaves as $chave) {
            $this->armazenamento->registrar($chave, $agora);
        }

        if (random_int(1, 100) <= self::PURGE_CHANCE) {
            $this->armazenamento->removerVencidas($agora, $this->janela);
        }
    }

    /**
     * @param list<string> $chaves
     * @return list<string>
     */
    private function normalizar(array $chaves): array
    {
        $saida = [];

        foreach ($chaves as $chave) {
            $limpa = strtolower(trim($chave));

            if ($limpa === '') {
                throw new InvalidArgumentException('As chaves do limite de tentativas não podem ser vazias.');
            }

            $saida[$limpa] = $limpa;
        }

        return array_values($saida);
    }
}
