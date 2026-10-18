<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LoginService;
use Haki\Auth\Auth;
use Haki\Http\Request;
use Haki\Http\Response;

final class AuthController
{
    public function __construct(private readonly LoginService $login)
    {
    }

    public function login(Request $request, Response $response, Auth $auth): Response
    {
        $usuario = $this->login->autenticar($request->input('email'), $request->input('senha'));

        $tokens = $auth->login($usuario->id, ['role' => $usuario->perfil]);

        return $response->json($tokens);
    }

    public function refresh(Request $request, Auth $auth): array
    {
        return $auth->refresh($request->input('refresh_token'));
    }

    public function logout(Request $request, Auth $auth): null
    {
        $auth->logout($request);

        return null; // 204 sem conteúdo
    }

    public function eu(Request $request): array
    {
        return ['usuario' => $request->user()];
    }
}
