# Haki

Miniframework PHP para APIs REST: rotas, injeção de dependências, middleware e autenticação JWT própria (HS256/RS256, refresh token e logout). Não depende de nenhuma biblioteca externa.

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new Haki\Haki(dirname(__DIR__));

$app->get('/ola/{nome}', fn (string $nome) => ['mensagem' => "Olá, $nome"]);
$app->get('/perfil', fn (Haki\Http\Request $r) => $r->user())->auth();
$app->get('/admin', fn () => ['ok' => true])->role('admin');

$app->run();
```

## Requisitos

- PHP 8.2+
- Extensões `json`, `mbstring` e `openssl`
- Extensão `pdo_sqlite`, apenas se você usar a autenticação JWT

## Instalação

```bash
composer require shankscode/haki
```

O pacote traz apenas o framework (`src/`) e o comando `vendor/bin/haki`. As pastas da sua aplicação (`app/`, `routes/`, `public/`...) você cria seguindo a estrutura recomendada na documentação.

## Primeiros passos

```bash
php vendor/bin/haki key:generate   # cria JWT_SECRET no .env
php -S localhost:8000 -t public
```

## Documentação

A documentação completa, com a estrutura de pastas recomendada, exemplos de cada recurso e uma API completa, está no site do Haki.

## Desenvolvimento do pacote

```bash
composer install
composer test
```

## Licença

MIT
