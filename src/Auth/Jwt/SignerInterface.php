<?php

declare(strict_types=1);

namespace Haki\Auth\Jwt;

/**
 * Algoritmo de assinatura do JWT. Novos algoritmos entram como novas classes, sem alterar o Jwt.
 */
interface SignerInterface
{
    /** Nome usado no header "alg" (ex.: HS256). */
    public function algorithm(): string;

    public function sign(string $data): string;

    public function verify(string $data, string $signature): bool;
}
