# Haki

Miniframework PHP para APIs REST: rotas, injeção de dependências, middleware e autenticação JWT própria (HS256/RS256, refresh token e logout). Não depende de nenhuma biblioteca externa.

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new Haki\Haki(dirname(__DIR__));

$app->get('/ola/{nome}', function (string $nome) {
    return ['mensagem' => "Olá, $nome"];
});

$app->get('/perfil', [PerfilController::class, 'show'])->auth();
$app->get('/admin', [AdminController::class, 'index'])->role('admin');

$app->run();
```

## Requisitos

- PHP 8.2+
- Extensões `json`, `mbstring` e `openssl`
- Extensão `zip`, usada pelo Composer para baixar o pacote (sem ela aparece o erro "The zip extension and unzip/7z commands are both missing")
- Extensão `pdo_sqlite`, apenas se você usar a autenticação JWT

## Instalação

```bash
composer require shankscode/haki
php vendor/bin/haki init
```

O `init` cria a estrutura padrão do projeto, com uma rota e um controller de exemplo:

```
app/Controllers/HomeController.php
app/{Services,Repositories,Contracts,Models,Middleware}/
bootstrap/app.php
routes/api.php
public/index.php
storage/
.env  .env.example  .gitignore
```

As rotas ficam soltas em `routes/api.php`. O `$app` vem do `bootstrap/app.php`, que carrega o arquivo com `$app->routes(...)`:

```php
<?php

declare(strict_types=1);

use App\Controllers\HomeController;

/** @var Haki\Haki $app */

$app->get('/', [HomeController::class, 'index']);
```

## Rodando

```bash
php -S localhost:8000 -t public
```

Acesse `http://localhost:8000`.

## Comandos

| Comando | Faz |
| --- | --- |
| `php vendor/bin/haki init` | Cria a estrutura padrão do projeto |
| `php vendor/bin/haki key:generate` | Cria `JWT_SECRET` no `.env` (`--rsa` para chaves RS256) |
| `php vendor/bin/haki auth:install` | Cria o banco SQLite de tokens |
| `php vendor/bin/haki auth:clean` | Remove tokens vencidos |

## Desenvolvimento do pacote

```bash
composer install
composer test
```

## Licença

MIT
