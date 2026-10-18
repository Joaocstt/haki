<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\UsuarioRepositoryInterface;
use App\Models\Usuario;
use Haki\Exceptions\HttpException;

/**
 * Regra de negócio do login: confere as credenciais.
 * O controller só cuida de HTTP; o Haki só cuida dos tokens.
 */
final class LoginService
{
    public function __construct(private readonly UsuarioRepositoryInterface $usuarios)
    {
    }

    public function autenticar(mixed $email, mixed $senha): Usuario
    {
        if (!is_string($email) || !is_string($senha) || $email === '' || $senha === '') {
            throw new HttpException(400, 'Informe email e senha');
        }

        $usuario = $this->usuarios->buscarPorEmail($email);

        // Mesma mensagem para e-mail inexistente e senha errada: não revela quais e-mails existem.
        if ($usuario === null || !$usuario->senhaConfere($senha)) {
            throw new HttpException(401, 'E-mail ou senha incorretos');
        }

        return $usuario;
    }
}
