# Haki

**Documentação:** https://joaocstt.github.io/haki/

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
app/{Requests,Services,Repositories,Contracts,Models,Middleware}/
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

## Validação

```bash
php vendor/bin/haki make:request CriarClienteRequest
```

```php
final class CriarClienteRequest extends FormRequest
{
    public function rules(Rule $rule): array
    {
        return [
            'nome'  => $rule->text()->length(3, 100),
            'email' => $rule->email(),
            'idade' => $rule->integer()->min(18)->optional(),
            'cpf'   => $rule->cpf(),      // formatos prontos: cep, cpf, cnpj, phone, mobile, plate, uf...
        ];
    }
}

// No controller: se algo estiver inválido, responde 422 antes de chegar aqui.
public function store(CriarClienteRequest $request): array
{
    return ['cliente' => $this->clientes->criar($request->validated())];
}
```

## Interfaces sem bind

O `bootstrap/app.php` gerado pelo `init` liga as interfaces de `app/Contracts` às classes de `app/Repositories` pelo nome:

```php
$app->container()->discover('App\Contracts', 'App\Repositories');
// ProdutoRepositoryInterface → ProdutoRepository ou PdoProdutoRepository
```

## Tipagem obrigatória

O `bootstrap/app.php` gerado pelo `init` exige tipos em todo o código do projeto:

```php
$app->requireTypes();
```

Um parâmetro ou retorno sem tipo faz a API responder com erro, indicando a classe, o método e a linha. O `haki check` confere todas as classes de `app/` antes de publicar.

## Rodando

```bash
php -S localhost:8000 -t public
```

Acesse `http://localhost:8000`.

## Comandos

| Comando | Faz |
| --- | --- |
| `php vendor/bin/haki init` | Cria a estrutura padrão do projeto |
| `php vendor/bin/haki make:request Nome` | Cria uma classe de validação em `app/Requests` |
| `php vendor/bin/haki check` | Confere interfaces, controllers e dependências antes de publicar |
| `php vendor/bin/haki db` | Testa a conexão com o banco configurado no `.env` (`DB_*`) |
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
