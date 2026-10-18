<?php

declare(strict_types=1);

namespace App\Controllers;

use Haki\Http\Request;

final class AdminController
{
    public function painel(Request $request): array
    {
        return [
            'mensagem' => 'Bem-vindo ao painel, ' . $request->user()?->id,
            'perfil' => $request->user()?->role,
        ];
    }
}
