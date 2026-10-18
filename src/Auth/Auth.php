<?php

declare(strict_types=1);

namespace Haki\Auth;

use Closure;
use Haki\Auth\Jwt\Jwt;
use Haki\Auth\Storage\RefreshTokenRecord;
use Haki\Auth\Storage\TokenStore;
use Haki\Exceptions\AuthenticationException;
use Haki\Http\Request;
use InvalidArgumentException;

/**
 * Emite, renova, valida e revoga tokens.
 *
 * A validação das credenciais (senha, e-mail...) é responsabilidade da sua aplicação:
 * chame login() somente depois de confirmar quem é o usuário.
 */
final class Auth
{
    /** Chance (em %) de limpar registros vencidos a cada validação. */
    private const PURGE_CHANCE = 1;

    private readonly Jwt $jwt;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock relógio injetável, para testes
     */
    public function __construct(
        private readonly AuthConfig $config,
        private readonly TokenStore $store,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->jwt = new Jwt($config->signer, $config->issuer, $this->clock);
    }

    /**
     * Inicia uma sessão para um usuário já validado.
     *
     *     $tokens = $auth->login($usuario->id, ['role' => 'admin']);
     *
     * @param array<string, mixed> $claims dados extras no token (nunca senhas)
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function login(string|int $userId, array $claims = []): array
    {
        $reserved = array_intersect(array_keys($claims), Identity::RESERVED);

        if ($reserved !== []) {
            throw new InvalidArgumentException('Claims reservados não podem ser definidos: ' . implode(', ', $reserved) . '.');
        }

        return $this->issue((string) $userId, $claims, bin2hex(random_bytes(16)));
    }

    /**
     * Troca um refresh token válido por um novo par de tokens (rotação).
     *
     * Se um refresh token já usado aparecer de novo, a sessão inteira é revogada.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function refresh(?string $refreshToken): array
    {
        if ($refreshToken === null || $refreshToken === '') {
            throw new AuthenticationException('Refresh token não informado.', 'Refresh token inválido ou expirado');
        }

        $now = ($this->clock)();
        $hash = hash('sha256', $refreshToken);
        $record = $this->store->findRefreshToken($hash);

        if ($record === null) {
            throw new AuthenticationException('Refresh token não encontrado.', 'Refresh token inválido ou expirado');
        }

        if ($record->usedAt !== null) {
            $this->revokeSession($record, $now);
        }

        if ($record->revokedAt !== null) {
            throw new AuthenticationException('Refresh token revogado (logout).', 'Refresh token inválido ou expirado');
        }

        if ($record->expiresAt <= $now) {
            throw new AuthenticationException('Refresh token expirado.', 'Refresh token inválido ou expirado');
        }

        $revokedBefore = $this->store->userRevokedBefore($record->userId);

        if ($revokedBefore !== null && $record->createdAt <= $revokedBefore) {
            throw new AuthenticationException('Refresh token revogado (logoutAll).', 'Refresh token inválido ou expirado');
        }

        if (!$this->store->consumeRefreshToken($hash, $now)) {
            // Outra requisição usou o mesmo token ao mesmo tempo.
            $this->revokeSession($record, $now);
        }

        return $this->issue($record->userId, $record->claims, $record->familyId);
    }

    /**
     * Valida um access token e devolve a identidade.
     *
     * @throws AuthenticationException
     */
    public function authenticate(string $token): Identity
    {
        $claims = $this->jwt->decode($token);

        if ($this->store->isAccessTokenRevoked((string) $claims['jti'])) {
            throw new AuthenticationException('Token revogado (logout).');
        }

        $revokedBefore = $this->store->userRevokedBefore((string) $claims['sub']);

        if ($revokedBefore !== null && $claims['iat'] <= $revokedBefore) {
            throw new AuthenticationException('Token revogado (logoutAll).');
        }

        if (random_int(1, 100) <= self::PURGE_CHANCE) {
            $this->store->purgeExpired(($this->clock)());
        }

        return new Identity($claims);
    }

    /**
     * Encerra a sessão atual: revoga o access token e os refresh tokens desse login.
     * Use em uma rota com ->auth().
     */
    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('logout() precisa de uma rota protegida com ->auth().');
        }

        $this->store->revokeAccessToken((string) $user->get('jti'), (int) $user->get('exp'));

        $family = $user->get('fid');

        if (is_string($family)) {
            $this->store->revokeFamily($family, ($this->clock)());
        }
    }

    /**
     * Revoga todos os tokens já emitidos para o usuário (todas as sessões).
     */
    public function logoutAll(string|int $userId): void
    {
        $this->store->revokeUser((string) $userId, ($this->clock)());
    }

    /**
     * @param array<string, mixed> $claims
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    private function issue(string $userId, array $claims, string $familyId): array
    {
        $now = ($this->clock)();

        $payload = ['sub' => $userId] + $claims + [
            'iat' => $now,
            'exp' => $now + $this->config->expiresIn,
            'jti' => bin2hex(random_bytes(16)),
            'fid' => $familyId,
        ];

        if ($this->config->issuer !== null) {
            $payload['iss'] = $this->config->issuer;
        }

        $refreshToken = bin2hex(random_bytes(32));

        $this->store->saveRefreshToken(new RefreshTokenRecord(
            hash('sha256', $refreshToken),
            $userId,
            $familyId,
            $claims,
            $now,
            $now + $this->config->refreshIn,
        ));

        return [
            'access_token' => $this->jwt->encode($payload),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->expiresIn,
        ];
    }

    private function revokeSession(RefreshTokenRecord $record, int $now): never
    {
        $this->store->revokeFamily($record->familyId, $now);
        $this->store->revokeUser($record->userId, $now);

        throw new AuthenticationException(
            'Refresh token reutilizado: possível roubo de token. Todas as sessões do usuário foram revogadas.',
            'Refresh token inválido ou expirado',
        );
    }
}
