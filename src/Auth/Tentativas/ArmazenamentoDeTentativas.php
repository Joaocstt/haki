<?php

declare(strict_types=1);

namespace Haki\Auth\Tentativas;

/**
 * Guarda as falhas de login. Precisa ser compartilhado entre os servidores, senão cada um conta à sua maneira.
 */
interface ArmazenamentoDeTentativas
{
    /** Registra uma falha da chave no instante $agora (timestamp). */
    public function registrar(string $chave, int $agora): void;

    /** Quantas falhas a chave teve depois de $agora - $janela. */
    public function falhas(string $chave, int $agora, int $janela): int;

    /** Esquece as falhas da chave (usado após um login bem-sucedido). */
    public function limpar(string $chave): void;

    /** Remove falhas mais antigas que a janela. Retorna quantas saíram. */
    public function removerVencidas(int $agora, int $janela): int;
}
