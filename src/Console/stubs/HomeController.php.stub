<?php

declare(strict_types=1);

namespace App\Controllers;

use Haki\Http\Request;
use Haki\Http\Response;

final class HomeController
{
    public function index(Request $request, Response $response): Response
    {
        return $response->json([
            'mensagem' => 'Bem-vindo ao Haki!',
            'caminho' => $request->path(),
        ]);
    }
}
