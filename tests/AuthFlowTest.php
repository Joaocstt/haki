<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Auth;
use Haki\Auth\AuthConfig;
use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Exceptions\AuthenticationException;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    private int $now = 1_800_000_000;

    private Haki $app;

    private Auth $auth;

    protected function setUp(): void
    {
        $this->app = new Haki(sys_get_temp_dir(), debug: true);
        $this->app->auth(['secret' => TestKeys::SECRET, 'expires_in' => 900, 'refresh_in' => 3600]);

        $config = $this->app->container()->get(AuthConfig::class);
        $this->auth = new Auth($config, new ArrayTokenStore(), fn (): int => $this->now);
        $this->app->container()->instance(Auth::class, $this->auth);

        $this->app->get('/publica', fn () => ['ok' => true]);
        $this->app->get('/perfil', fn (Request $r) => ['usuario' => $r->user()])->auth();
        $this->app->get('/admin', fn () => ['admin' => true])->role('admin');
        $this->app->get('/gestao', fn () => ['ok' => true])->role('admin', 'gerente');
        $this->app->post('/logout', function (Request $r, Auth $auth) {
            $auth->logout($r);

            return null;
        })->auth();
    }

    private function get(string $path, ?string $token = null): \Haki\Http\Response
    {
        $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];

        return $this->app->handle(Request::create('GET', $path, [], $headers));
    }

    public function testPublicRouteNeedsNoToken(): void
    {
        self::assertSame(200, $this->get('/publica')->getStatus());
    }

    public function testMissingTokenReturns401WithChallenge(): void
    {
        $response = $this->get('/perfil');

        self::assertSame(401, $response->getStatus());
        self::assertSame('Bearer', $response->getHeader('WWW-Authenticate'));
        self::assertSame('Token não informado', $response->getJson()['erro']);
    }

    public function testInvalidTokenReturns401(): void
    {
        self::assertSame(401, $this->get('/perfil', 'nao.e.token')->getStatus());
    }

    public function testValidTokenExposesIdentityWithoutInternalClaims(): void
    {
        $tokens = $this->auth->login(7, ['role' => 'membro', 'nome' => 'Usopp']);

        $response = $this->get('/perfil', $tokens['access_token']);

        self::assertSame(200, $response->getStatus());
        self::assertSame(['usuario' => ['id' => '7', 'role' => 'membro', 'nome' => 'Usopp']], $response->getJson());
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(900, $tokens['expires_in']);
    }

    public function testRoleChecks(): void
    {
        $membro = $this->auth->login(1, ['role' => 'membro'])['access_token'];
        $admin = $this->auth->login(2, ['role' => 'admin'])['access_token'];
        $gerente = $this->auth->login(3, ['role' => 'gerente'])['access_token'];

        self::assertSame(401, $this->get('/admin')->getStatus(), 'role() sem token continua 401');
        self::assertSame(403, $this->get('/admin', $membro)->getStatus());
        self::assertSame(200, $this->get('/admin', $admin)->getStatus());
        self::assertSame(200, $this->get('/gestao', $gerente)->getStatus());
        self::assertSame(403, $this->get('/gestao', $membro)->getStatus());
    }

    public function testExpiredAccessToken(): void
    {
        $token = $this->auth->login(1)['access_token'];
        $this->now += 901;

        self::assertSame(401, $this->get('/perfil', $token)->getStatus());
    }

    public function testLogoutRevokesAccessAndRefreshTokens(): void
    {
        $tokens = $this->auth->login(1);
        $headers = ['Authorization' => 'Bearer ' . $tokens['access_token']];

        self::assertSame(204, $this->app->handle(Request::create('POST', '/logout', [], $headers))->getStatus());
        self::assertSame(401, $this->get('/perfil', $tokens['access_token'])->getStatus());

        $this->expectException(AuthenticationException::class);
        $this->auth->refresh($tokens['refresh_token']);
    }

    public function testLogoutAllRevokesEverySession(): void
    {
        $celular = $this->auth->login(1);
        $notebook = $this->auth->login(1);
        $outroUsuario = $this->auth->login(2);

        $this->auth->logoutAll(1);

        self::assertSame(401, $this->get('/perfil', $celular['access_token'])->getStatus());
        self::assertSame(401, $this->get('/perfil', $notebook['access_token'])->getStatus());
        self::assertSame(200, $this->get('/perfil', $outroUsuario['access_token'])->getStatus());

        $this->now++;
        self::assertSame(200, $this->get('/perfil', $this->auth->login(1)['access_token'])->getStatus(), 'Novo login depois funciona');
    }

    public function testRefreshRotatesTokensAndKeepsClaims(): void
    {
        $first = $this->auth->login(1, ['role' => 'admin']);
        $this->now += 10;

        $second = $this->auth->refresh($first['refresh_token']);

        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertSame(200, $this->get('/admin', $second['access_token'])->getStatus());
    }

    public function testReusedRefreshTokenRevokesTheWholeSession(): void
    {
        $first = $this->auth->login(1);
        $second = $this->auth->refresh($first['refresh_token']);

        try {
            $this->auth->refresh($first['refresh_token']); // reuso: possível roubo
            self::fail('Reuso deveria ser bloqueado.');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('reutilizado', (string) $e->getHint());
        }

        self::assertSame(401, $this->get('/perfil', $second['access_token'])->getStatus());

        $this->expectException(AuthenticationException::class);
        $this->auth->refresh($second['refresh_token']);
    }

    public function testExpiredOrUnknownRefreshToken(): void
    {
        $tokens = $this->auth->login(1);
        $this->now += 3601;

        foreach ([$tokens['refresh_token'], 'inexistente', '', null] as $refresh) {
            try {
                $this->auth->refresh($refresh);
                self::fail('Refresh deveria falhar.');
            } catch (AuthenticationException $e) {
                self::assertSame(401, $e->getStatus());
            }
        }
    }

    public function testReservedClaimsCannotBeOverridden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->login(1, ['exp' => PHP_INT_MAX]);
    }
}
