<?php

declare(strict_types=1);

namespace Haki\Auth\Jwt;

use Closure;
use Haki\Exceptions\AuthenticationException;
use JsonException;

/**
 * Codifica e valida JSON Web Tokens.
 *
 * O algoritmo é sempre o do signer configurado: o "alg" do token nunca escolhe o algoritmo.
 */
final class Jwt
{
    private const REQUIRED_CLAIMS = ['sub', 'iat', 'exp', 'jti'];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock relógio injetável, para testes
     */
    public function __construct(
        private readonly SignerInterface $signer,
        private readonly ?string $issuer = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims): string
    {
        $header = ['alg' => $this->signer->algorithm(), 'typ' => 'JWT'];

        $input = Base64Url::encode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $input . '.' . Base64Url::encode($this->signer->sign($input));
    }

    /**
     * Valida o token e devolve os claims.
     *
     * @return array<string, mixed>
     * @throws AuthenticationException
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new AuthenticationException('Token malformado: esperado header.payload.assinatura.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJson($encodedHeader, 'header');
        $claims = $this->decodeJson($encodedPayload, 'payload');
        $signature = Base64Url::decode($encodedSignature);

        if ($signature === null) {
            throw new AuthenticationException('Token malformado: assinatura não é base64url válido.');
        }

        if (($header['alg'] ?? null) !== $this->signer->algorithm()) {
            throw new AuthenticationException(sprintf(
                'Algoritmo do token (%s) diferente do configurado (%s).',
                is_string($header['alg'] ?? null) ? $header['alg'] : 'ausente',
                $this->signer->algorithm(),
            ));
        }

        if (!$this->signer->verify($encodedHeader . '.' . $encodedPayload, $signature)) {
            throw new AuthenticationException('Assinatura inválida: o token foi alterado ou assinado com outra chave.');
        }

        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (!isset($claims[$claim])) {
                throw new AuthenticationException("Claim obrigatório ausente: {$claim}.");
            }
        }

        if (!is_int($claims['exp']) || !is_int($claims['iat'])) {
            throw new AuthenticationException('Os claims exp e iat devem ser timestamps inteiros.');
        }

        if ($claims['exp'] <= ($this->clock)()) {
            throw new AuthenticationException('Token expirado.');
        }

        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            throw new AuthenticationException('Emissor (iss) do token não confere.');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $part, string $label): array
    {
        $json = Base64Url::decode($part);

        if ($json === null) {
            throw new AuthenticationException("Token malformado: {$label} não é base64url válido.");
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthenticationException("Token malformado: {$label} não é JSON válido.");
        }

        if (!is_array($data)) {
            throw new AuthenticationException("Token malformado: {$label} deve ser um objeto JSON.");
        }

        return $data;
    }
}
