<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Auth;
use Haki\Auth\AuthConfig;
use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Exceptions\AuthenticationException;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

/**
 * Ao trocar o perfil de um usuário, o padrão é chamar logoutAll() na mesma operação.
 * Este teste garante o efeito: o token antigo e o refresh antigo param de valer, e um novo login
 * já vem com o perfil novo.
 */
final class RevogacaoAoMudarPerfilTest extends TestCase
{
    private int $agora = 1_800_000_000;

    private function auth(ArrayTokenStore $store): Auth
    {
        $config = AuthConfig::fromArray(['algorithm' => 'HS256', 'secret' => TestKeys::SECRET], sys_get_temp_dir());

        return new Auth($config, $store, function (): int {
            return $this->agora;
        });
    }

    public function testMudancaDePerfilInvalidaSessoesAntigasENovoLoginUsaOPerfilNovo(): void
    {
        $store = new ArrayTokenStore();
        $auth = $this->auth($store);

        $antigo = $auth->login('7', ['role' => 'usuario']);
        self::assertSame('7', $auth->authenticate($antigo['access_token'])->get('sub'));

        // Operação de troca de perfil: altera o dado e revoga as sessões na mesma ação.
        // O iat tem precisão de segundos, então a troca acontece num segundo posterior ao login.
        $this->agora++;
        $auth->logoutAll('7');

        try {
            $auth->authenticate($antigo['access_token']);
            self::fail('O access token antigo deveria estar revogado.');
        } catch (AuthenticationException) {
        }

        try {
            $auth->refresh($antigo['refresh_token']);
            self::fail('O refresh token antigo deveria estar revogado.');
        } catch (AuthenticationException) {
        }

        // Um login feito logo depois vale normalmente, já com o perfil novo.
        $this->agora++;
        $novo = $auth->login('7', ['role' => 'admin']);
        $identidade = $auth->authenticate($novo['access_token']);

        self::assertSame('admin', $identidade->get('role'));
    }
}
