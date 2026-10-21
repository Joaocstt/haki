<?php

declare(strict_types=1);

namespace Haki\Middleware;

use Closure;
use Haki\Auth\Tentativas\ArmazenamentoDeTentativas;
use Haki\Exceptions\TooManyAttemptsException;
use Haki\Http\Request;
use Haki\Http\Response;
use InvalidArgumentException;

/**
 * Limite de requisições por IP e por rota. Criado pelo Haki quando a rota usa ->limite(...).
 *
 * Cada chamada conta, com ou sem sucesso. Ao passar do máximo na janela, responde 429
 * sem chegar ao controller.
 */
final class LimitarRequisicoes implements MiddlewareInterface
{
    /** Chance (em %) de limpar registros vencidos a cada requisição bloqueada ou aceita. */
    private const PURGE_CHANCE = 1;

    /** @var Closure(): int */
    private readonly Closure $relogio;

    /**
     * @param string $rota identificação da rota, ex.: "POST /login"
     * @param (Closure(): int)|null $relogio relógio injetável, para testes
     */
    public function __construct(
        private readonly ArmazenamentoDeTentativas $armazenamento,
        private readonly int $maximo,
        private readonly int $janela,
        private readonly string $rota,
        ?Closure $relogio = null,
    ) {
        if ($maximo < 1 || $janela < 1) {
            throw new InvalidArgumentException('O máximo e a janela do limite precisam ser maiores que zero.');
        }

        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function handle(Request $request, callable $next): Response
    {
        $agora = ($this->relogio)();
        $chave = 'rota:' . $this->rota . '|ip:' . ($request->ip() ?? 'desconhecido');

        if ($this->armazenamento->falhas($chave, $agora, $this->janela) >= $this->maximo) {
            throw new TooManyAttemptsException($this->janela);
        }

        $this->armazenamento->registrar($chave, $agora);

        if (random_int(1, 100) <= self::PURGE_CHANCE) {
            $this->armazenamento->removerVencidas($agora, $this->janela);
        }

        return $next($request);
    }
}
