# PRD — Haki

**Miniframework PHP simples para APIs REST, com autenticação JWT própria**

- **Nome do projeto:** Haki
- **Pacote Composer:** `shankscode/haki`
- **Namespace raiz:** `Haki\`
- **Versão inicial:** 1.0.0
- **Linguagem:** PHP 8.2+
- **Autoload:** PSR-4
- **Licença:** MIT
- **Dependências de runtime:** nenhuma biblioteca externa — apenas extensões nativas (`json`, `mbstring`, `openssl`; `pdo_sqlite` sugerida, necessária só para a autenticação)
- **Dependências de desenvolvimento:** PHPUnit
- **Público-alvo:** iniciantes e desenvolvedores PHP que querem criar APIs rapidamente, sem a curva de aprendizado de um framework grande.

---

## 1. Visão geral

O Haki é um miniframework PHP para criar APIs REST com poucas linhas de código. Ele reúne em um único pacote:

- Roteamento HTTP com parâmetros e grupos.
- Request e Response simples, com JSON automático.
- Injeção automática de dependências nos controllers.
- Middleware.
- Autenticação JWT própria (HS256 e RS256), com refresh token e revogação.
- Armazenamento de tokens em SQLite, criado automaticamente.
- Respostas de erro padronizadas.

O Haki não depende de Laravel, Symfony ou qualquer outro framework.

## 2. Princípios

1. **Simples para iniciantes.** Uma API funcional deve caber em 5 linhas.
2. **Zero configuração para começar.** Tudo tem um padrão que funciona; configurar é opcional.
3. **Complexidade progressiva.** Closures → controllers → middleware → auth. Nenhum recurso avançado é pré-requisito.
4. **Erros que ensinam.** Em desenvolvimento, mensagens em português dizem o que aconteceu e como resolver.
5. **Sem mágica.** Sem facades ou chamadas estáticas escondidas; o que o usuário usa é o que ele vê.
6. **Seguro por padrão.** Sem segredos padrão, sem stack trace em produção, JWT validado rigorosamente.
7. **Zero dependências externas em runtime.**

### Exemplo de referência

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Haki\Haki;

$app = new Haki(__DIR__);

$app->get('/ola/{nome}', fn (string $nome) => ['mensagem' => "Olá, $nome"]);

$app->run();
```

## 3. Escopo

### 3.1 Incluído na v1.0

| Área | Itens |
| --- | --- |
| Aplicação | Classe `Haki`, carregamento de `.env`, modo dev/prod |
| Rotas | GET, POST, PUT, PATCH, DELETE, parâmetros `{id}`, grupos, closures e controllers, 404/405 |
| HTTP | `Request`, `Response`, retorno de array convertido em JSON |
| Injeção | `Request`, `Response`, `Auth` e parâmetros de rota injetados por tipo/nome |
| Middleware | Global, por grupo e por rota |
| JWT | Implementação própria, HS256 e RS256 |
| Auth | `login()`, `refresh()`, `logout()`, `logoutAll()`, `->auth()`, `->role()`, `Request::user()` |
| Tokens | Refresh token com rotação e detecção de reuso, revogação por `jti` e por usuário |
| Storage | `SqliteTokenStore` (padrão, automático), `ArrayTokenStore` (testes), interface `TokenStore` |
| CLI | `init`, `key:generate`, `auth:install`, `auth:clean` |
| Erros | JSON padronizado, detalhes só em dev |
| Depuração | Função global `haki()` (dump legível em HTML e terminal) |
| Validação | `FormRequest` com `$rule->...`, formatos prontos (CEP, CPF, CNPJ, telefone, placa, UF...), `$request->validate()`, comando `make:request` |
| Qualidade | Testes PHPUnit das partes principais |

### 3.2 Fora do escopo da v1.0

- ORM ou query builder.

- Templates/views.
- Filas, eventos, cache.
- Rate limiting integrado.
- Geração de documentação OpenAPI.
- Facades e helpers globais (exceto `haki()`).
- Esqueleto `composer create-project`: o pacote traz só o framework; a estrutura padrão da aplicação é gerada pelo comando `haki init` (uma rota e um controller de exemplo).

## 4. Requisitos funcionais

### RF01 — Aplicação

A classe `Haki\Haki` é o ponto de entrada.

```php
$app = new Haki(__DIR__);
// ... rotas
$app->run();
```

- O argumento é o diretório base do projeto (`basePath`).
- Se existir `basePath/.env`, ele é carregado por um leitor simples embutido (formato `CHAVE=valor`, comentários com `#`). Variáveis já definidas no ambiente não são sobrescritas.
- O usuário pode usar `vlucas/phpdotenv` ou outra solução; o leitor embutido apenas não sobrescreve.
- O modo é definido por `APP_ENV` (`development` ou `production`). **Padrão: `production`** (seguro por padrão).
- `run()` captura a requisição atual, resolve a rota, executa e envia a resposta uma única vez.
- `handle(Request $request): Response` executa sem enviar — usado nos testes.

### RF02 — Rotas

```php
$app->get('/usuarios', [UsuarioController::class, 'index']);
$app->post('/usuarios', [UsuarioController::class, 'store']);
$app->get('/usuarios/{id}', [UsuarioController::class, 'show']);
$app->put('/usuarios/{id}', [UsuarioController::class, 'update']);
$app->patch('/usuarios/{id}', [UsuarioController::class, 'patch']);
$app->delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

$app->get('/status', fn () => ['ok' => true]);

$app->group('/api', function (Haki $app) {
    $app->get('/produtos', [ProdutoController::class, 'index']);
})->auth();
```

**Critérios de aceite:**

- Handler pode ser closure ou `[Classe::class, 'metodo']`.
- Parâmetros `{nome}` são extraídos na ordem em que aparecem.
- Barra final é ignorada (`/usuarios/` = `/usuarios`).
- Query string não interfere na correspondência.
- Caminho inexistente → **404**.
- Caminho existe, mas com outro método → **405**, com cabeçalho `Allow`.
- Grupos aplicam prefixo e encadeiam `->auth()`, `->role()` e `->middleware()` a todas as rotas internas.
- Em dev, o erro 404 sugere rotas parecidas.

### RF03 — Request

```php
$request->method();               // 'POST'
$request->path();                 // '/usuarios/10'
$request->param('id');            // parâmetro da rota
$request->query('pagina', 1);     // query string, com padrão
$request->input('nome');          // corpo JSON ou form
$request->all();                  // corpo completo
$request->header('Accept');
$request->bearerToken();          // token do cabeçalho Authorization
$request->user();                 // identidade autenticada ou null
```

- O corpo JSON é decodificado automaticamente quando `Content-Type: application/json`.
- JSON inválido no corpo → **400**.
- Todos os valores são tratados como entrada não confiável; validar é responsabilidade da aplicação.

### RF04 — Response

Contrato: **os métodos de `Response` retornam um objeto `Response`; quem envia a resposta é o `Haki::run()`, uma única vez.**

```php
return $response->json(['usuario' => $usuario], 200);
return $response->responseJson(['usuario' => $usuario], 200); // alias de json()

return $response->status(204);
return $response->header('X-Total', '42')->json($lista);
```

Retornos automáticos de handlers:

| Retorno do handler | Resposta |
| --- | --- |
| `array` ou objeto `JsonSerializable` | JSON com status 200 |
| `Response` | enviada como está |
| `string` | `text/plain` com status 200 |
| `null` | 204 sem corpo |

**Requisitos:**

- `json()` / `responseJson()` são métodos de instância, nunca estáticos.
- `Content-Type: application/json; charset=utf-8`.
- Serialização com `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_UNICODE` e `JSON_UNESCAPED_SLASHES`.
- Falha de serialização → **500** com o formato padrão de erro.
- Uma resposta enviada não pode ser enviada novamente.

### RF05 — Injeção automática de dependências

O Haki resolve os parâmetros do handler (closure ou método do controller) por reflexão:

| Parâmetro | Resolvido como |
| --- | --- |
| Tipo `Request` | requisição atual |
| Tipo `Response` | nova resposta |
| Tipo `Auth` | serviço de autenticação configurado |
| Nome igual a um parâmetro de rota (`$id`) | valor da URL, convertido para `int`/`float`/`string` conforme o tipo |
| Parâmetro com valor padrão | o valor padrão |
| Outra classe sem argumentos obrigatórios no construtor | instanciada automaticamente |

```php
class UsuarioController
{
    public function show(int $id, Request $request, Response $response)
    {
        return $response->json([
            'id'      => $id,
            'usuario' => $request->user(), // identidade vinda do token validado
        ]);
    }
}
```

- A ordem dos parâmetros no método é livre.
- Controllers são instanciados pelo mesmo mecanismo (o construtor também recebe injeção).
- Parâmetro que não pode ser resolvido → erro claro em dev, por exemplo: *"Não sei como injetar `$repo` (`UsuarioRepository`) em `UsuarioController::show`. O construtor dessa classe exige argumentos."*
- Parâmetro de rota `int` com valor não numérico → **404**.

### RF06 — Middleware

```php
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
```

```php
$app->middleware(new Cors());                       // global
$app->group('/api', fn ($app) => ...)->middleware(new Log()); // grupo
$app->get('/x', $handler)->middleware(new Teste()); // rota
```

**Ordem de execução:**

1. Middlewares globais, na ordem de registro.
2. Middlewares do grupo.
3. `Authenticate` (se `->auth()`), sempre antes de `AuthorizeRole`.
4. `AuthorizeRole` (se `->role()`).
5. Middlewares da rota.
6. Handler.

- Um middleware pode interromper o fluxo retornando um `Response` sem chamar `$next`.
- Um middleware pode alterar a resposta depois de chamar `$next`.
- Closures `fn (Request $r, callable $next) => ...` também são aceitas.

### RF07 — Configuração da autenticação

```php
// HS256 (padrão)
$app->auth([
    'secret'     => getenv('JWT_SECRET'),
    'expires_in' => 900,      // access token: 15 min (padrão)
    'refresh_in' => 604800,   // refresh token: 7 dias (padrão)
]);

// RS256
$app->auth([
    'algorithm'   => 'RS256',
    'private_key' => file_get_contents(__DIR__ . '/chaves/private.pem'),
    'public_key'  => file_get_contents(__DIR__ . '/chaves/public.pem'),
]);

// Opções comuns
//  'issuer'   => 'minha-api',                             // claim iss (opcional)
//  'database' => __DIR__ . '/storage/haki.sqlite',        // padrão: basePath/storage/haki.sqlite
//  'storage'  => new MeuRedisTokenStore($redis),          // substitui o SQLite
```

Sem `$app->auth([...])`, o Haki lê `JWT_SECRET`, `JWT_ALGORITHM`, `JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEY`, `JWT_TTL` e `JWT_REFRESH_TTL` do ambiente quando uma rota com `->auth()` for acessada.

**Validações (falha → exceção na configuração, nunca um padrão inseguro):**

- `algorithm` deve ser `HS256` ou `RS256`.
- HS256: `secret` obrigatório, com no mínimo 32 bytes.
- RS256: `private_key` (para emitir) e `public_key` (para validar) devem ser chaves PEM válidas, RSA com no mínimo 2048 bits. Um serviço que apenas valida pode informar somente a `public_key`.
- `expires_in` e `refresh_in` devem ser inteiros positivos.

### RF08 — JWT próprio

Implementação interna, sem bibliotecas externas.

**Formato:** `base64url(header).base64url(payload).base64url(assinatura)`

**Header:** `{"alg": "HS256" | "RS256", "typ": "JWT"}`

**Claims:**

| Claim | Descrição | Obrigatório |
| --- | --- | --- |
| `sub` | ID do usuário (string) | sim |
| `iat` | emitido em (timestamp) | sim |
| `exp` | expira em (timestamp) | sim |
| `jti` | ID único do token (`bin2hex(random_bytes(16))`) | sim |
| `fid` | ID da família/sessão de login (usado por `logout()`) | emitido sempre |
| `role` | perfil do usuário | não |
| `iss` | emissor, se configurado | se configurado |
| outros | claims extras passados em `login()` | não |

**Regras de validação (todas obrigatórias):**

1. O token deve ter exatamente 3 partes base64url válidas e JSON válido.
2. O `alg` do header deve ser **igual** ao algoritmo configurado. O algoritmo nunca é escolhido pelo token (`none` e outros são rejeitados).
3. HS256: assinatura com `hash_hmac('sha256', ...)`, comparada com `hash_equals()`.
4. RS256: verificação com `openssl_verify(..., OPENSSL_ALGO_SHA256)` usando a chave pública.
5. `exp` deve existir e estar no futuro.
6. `sub`, `iat` e `jti` devem existir.
7. `iss`, se configurado, deve coincidir.
8. O `jti` não pode estar revogado e o `iat` não pode ser anterior ao `revoked_before` do usuário.

Qualquer falha → **401**, com mensagem genérica (`Token inválido ou expirado`). O motivo detalhado aparece apenas em dev.

**Proibido:** colocar senhas ou dados sensíveis no token; registrar tokens completos em logs.

### RF09 — Login, refresh e logout

A validação de credenciais (senha, e-mail etc.) é responsabilidade da aplicação. O Haki só emite tokens para um usuário já validado.

```php
public function login(Request $request, Response $response, Auth $auth)
{
    // A aplicação valida as credenciais e encontra o $usuario.

    $tokens = $auth->login($usuario->id, ['role' => $usuario->perfil]);

    return $response->json($tokens);
}
```

Retorno de `login()` e `refresh()`:

```json
{
  "access_token": "eyJhbGciOi...",
  "refresh_token": "a3f9c2...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

```php
$tokens = $auth->refresh($request->input('refresh_token')); // novo par de tokens
$auth->logout($request);          // revoga o access token atual e a família do refresh token
$auth->logoutAll($usuario->id);   // revoga todos os tokens do usuário
```

**Refresh token:**

- Não é um JWT: é uma string aleatória (`bin2hex(random_bytes(32))`).
- No storage fica apenas o **SHA-256** do token, nunca o valor puro.
- Pertence a uma **família** (criada a cada `login()`).
- **Rotação:** cada `refresh()` consome o token usado e emite um novo par na mesma família.
- **Detecção de reuso:** se um refresh token já consumido for apresentado novamente, a família inteira é revogada e o `revoked_before` do usuário é atualizado (todos os access tokens dele deixam de valer).
- Refresh token inválido, expirado ou reutilizado → **401**.
- `refresh()` mantém os claims extras (`role` etc.) do login original. Se o perfil do usuário mudar, a aplicação deve chamar `logoutAll()` ou `login()` novamente.

### RF10 — Proteção de rotas e perfis

```php
$app->get('/perfil', [PerfilController::class, 'show'])->auth();
$app->get('/admin', [AdminController::class, 'index'])->auth()->role('admin');
$app->get('/painel', [PainelController::class, 'index'])->auth()->role('admin', 'gerente');
```

- Token lido do cabeçalho `Authorization: Bearer <token>`.
- Sem token, token inválido, expirado ou revogado → **401**, com cabeçalho `WWW-Authenticate: Bearer`.
- Autenticado, mas sem o perfil exigido → **403**.
- `->role()` sem `->auth()` aplica `->auth()` implicitamente.
- `->role()` aceita um ou mais perfis; basta o usuário ter um deles. A comparação é exata (sensível a maiúsculas).
- Rotas sem `->auth()` continuam públicas.

### RF11 — Identidade autenticada

```php
$usuario = $request->user();
```

- Retorna `null` se a rota não exige auth ou não há usuário autenticado.
- Retorna um objeto `Haki\Auth\Identity`, preenchido **somente** após a validação completa do token:

```php
$usuario->id;          // claim sub
$usuario->role;        // claim role ou null
$usuario->claims;      // array com todos os claims validados
$usuario->get('chave'); // claim específico
```

- `Identity` implementa `JsonSerializable`, então pode ser devolvida direto no JSON.
- A identidade representa **apenas os dados do token**; o Haki não consulta o banco da aplicação. Para checar bloqueio ou exclusão de usuário a cada requisição, a aplicação pode usar um middleware próprio depois de `->auth()`.

### RF12 — Armazenamento de tokens (SQLite)

Quando a autenticação é usada, o Haki usa `SqliteTokenStore` por padrão, **sem configuração**.

**Inicialização automática (na primeira requisição que usa auth):**

1. Verifica se a extensão `pdo_sqlite` está disponível. Se não estiver, o erro orienta: *"Ative `extension=pdo_sqlite` no seu php.ini."*
2. Cria a pasta e o arquivo (`basePath/storage/haki.sqlite` por padrão) se não existirem. Sem permissão de escrita → erro claro.
3. Executa `CREATE TABLE IF NOT EXISTS` (idempotente).
4. Lê a versão do schema em `haki_meta` e aplica migrações pendentes de versões futuras.

**Schema:**

```sql
CREATE TABLE IF NOT EXISTS haki_refresh_tokens (
    token_hash  TEXT PRIMARY KEY,
    user_id     TEXT NOT NULL,
    family_id   TEXT NOT NULL,
    claims      TEXT NOT NULL,          -- JSON com os claims extras do login
    created_at  INTEGER NOT NULL,       -- comparado com revoked_before (logoutAll)
    expires_at  INTEGER NOT NULL,
    used_at     INTEGER NULL,
    revoked_at  INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_haki_refresh_family ON haki_refresh_tokens (family_id);
CREATE INDEX IF NOT EXISTS idx_haki_refresh_user   ON haki_refresh_tokens (user_id);

CREATE TABLE IF NOT EXISTS haki_revoked_tokens (
    jti         TEXT PRIMARY KEY,
    expires_at  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS haki_user_revocations (
    user_id         TEXT PRIMARY KEY,
    revoked_before  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS haki_meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);
```

**Requisitos:**

- Conexão com `PRAGMA journal_mode = WAL` e `PRAGMA busy_timeout = 5000`.
- Todas as queries com prepared statements.
- Consumo de refresh token atômico: `UPDATE ... SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND revoked_at IS NULL`. Zero linhas afetadas → token reutilizado ou inválido.
- Limpeza automática de registros vencidos em ~1% das requisições que usam auth, além do comando `auth:clean`.
- O banco **nunca** fica dentro de `vendor/`.

**Interface para trocar o storage:**

```php
interface TokenStore
{
    public function revokeAccessToken(string $jti, int $expiresAt): void;
    public function isAccessTokenRevoked(string $jti): bool;
    public function revokeUser(string $userId, int $before): void;
    public function userRevokedBefore(string $userId): ?int;

    public function saveRefreshToken(string $hash, string $userId, string $familyId, array $claims, int $expiresAt): void;
    public function consumeRefreshToken(string $hash): ?RefreshTokenRecord; // null = inválido/reutilizado
    public function findRefreshToken(string $hash): ?RefreshTokenRecord;
    public function revokeFamily(string $familyId): void;

    public function purgeExpired(): int;
}
```

Implementações incluídas: `SqliteTokenStore` (padrão) e `ArrayTokenStore` (em memória, para testes).

### RF13 — CLI

Executável `vendor/bin/haki`, com apenas três comandos:

| Comando | Função |
| --- | --- |
| `haki key:generate` | Gera um `JWT_SECRET` aleatório de 64 bytes e grava no `.env` (cria o arquivo se não existir; pede confirmação antes de sobrescrever) |
| `haki key:generate --rsa` | Gera `storage/keys/private.pem` e `public.pem` (RSA 2048) |
| `haki auth:install` | Cria o banco SQLite e as tabelas explicitamente (útil em deploy) |
| `haki auth:clean` | Remove tokens vencidos (para uso em cron) |

### RF14 — Tratamento de erros

**Formato padrão:**

```json
{ "erro": "Rota não encontrada", "status": 404 }
```

Em dev, um campo extra `"detalhes"` traz a dica de solução; em erros 500, traz também exceção, mensagem, arquivo, linha e trace. Em produção, esse campo nunca aparece, e exceções não tratadas viram `{"erro": "Erro interno do servidor", "status": 500}`.

| Situação | Código |
| --- | ---: |
| Sucesso | 200 |
| Criado | 201 |
| Sem conteúdo | 204 |
| Requisição inválida (ex.: JSON malformado) | 400 |
| Não autenticado | 401 |
| Sem permissão | 403 |
| Rota não encontrada | 404 |
| Método não permitido | 405 |
| Erro interno | 500 |

A aplicação pode lançar `Haki\Exceptions\HttpException(404, 'Usuário não encontrado')` em qualquer lugar para responder com esse formato.

### RF15 — Variáveis de ambiente

```env
APP_ENV=production
JWT_SECRET=gerado-por-haki-key-generate
JWT_ALGORITHM=HS256
JWT_TTL=900
JWT_REFRESH_TTL=604800
# Para RS256:
# JWT_PRIVATE_KEY=storage/keys/private.pem
# JWT_PUBLIC_KEY=storage/keys/public.pem
```

O README deve recomendar adicionar ao `.gitignore`:

```gitignore
.env
storage/*.sqlite*
storage/keys/
```

### RF16 — Distribuição

- `composer.json` válido para Packagist: nome `shankscode/haki`, licença MIT, `php: ^8.2`, `ext-json`, `ext-openssl`, `ext-pdo_sqlite`, autoload PSR-4 `Haki\` → `src/`, `bin: ["bin/haki"]`.
- Versionamento semântico, primeira tag `v1.0.0`.
- Antes da publicação, deve funcionar via repositório `path` ou `vcs`.
- Após publicação: `composer require shankscode/haki`.

### RF17 — Documentação (README.md em português)

1. O que é o Haki.
2. Requisitos e instalação.
3. Primeira API em 5 minutos.
4. Rotas, parâmetros e grupos.
5. Controllers e injeção automática.
6. Request e Response (contrato de envio).
7. Middleware.
8. Autenticação: configuração HS256 e RS256.
9. Login, refresh e logout.
10. Proteção de rotas e perfis.
11. `Request::user()` e o que a identidade representa.
12. Armazenamento de tokens (SQLite e `TokenStore` customizado).
13. CLI.
14. Erros.
15. Variáveis de ambiente e `.gitignore`.
16. Segurança em produção (HTTPS, rotação de segredo, armazenamento do token no cliente).
17. Exemplo completo de API.
18. Limitações conhecidas e roadmap.

Todos os exemplos devem ser executáveis na versão publicada.

## 5. Requisitos não funcionais

- **Compatibilidade:** PHP 8.2+, qualquer servidor web (Apache, Nginx + PHP-FPM, `php -S`), sem framework.
- **Simplicidade:** API pública pequena; um iniciante deve entender o exemplo de referência sem ler a documentação.
- **Código:** `declare(strict_types=1)`, tipagem explícita, uma responsabilidade por classe, nomes consistentes.
- **Desempenho:** resolução de rotas sem processamento desnecessário; uma consulta ao SQLite por requisição protegida (verificação de revogação); nenhuma consulta em rotas públicas.
- **Segurança:** seção 8.

## 6. Arquitetura

### 6.1 O que o pacote distribui

Quem instala via `composer require shankscode/haki` recebe **apenas** o necessário para rodar (controlado por `.gitattributes` com `export-ignore`):

```text
vendor/shankscode/haki/
├── bin/haki
├── src/
├── composer.json
├── LICENSE
└── README.md
```

Testes, app de exemplo, PRD e site da documentação ficam só no repositório.

### 6.2 Código do framework (`src/`)

Organizado por responsabilidade; dependências apontam para interfaces (`ContainerInterface`, `MiddlewareInterface`, `SignerInterface`, `TokenStore`, `CommandInterface`).

```text
src/
├── Haki.php                      # ponto de entrada: monta e liga as peças
├── helpers.php                   # função global haki()
├── Http/                         # Request, Response, ResponseConverter, ResponseEmitter
├── Routing/                      # Router, Route, RouteGroup, RouteMatch
├── Container/                    # Container (autowiring), ContainerInterface, Invoker
├── Middleware/                   # MiddlewareInterface, Pipeline
├── Auth/
│   ├── Auth.php, AuthConfig.php, Identity.php
│   ├── Jwt/                      # Jwt, Base64Url, SignerInterface, HmacSigner, RsaSigner
│   ├── Middleware/               # Authenticate, AuthorizeRole
│   └── Storage/                  # TokenStore, SqliteTokenStore, ArrayTokenStore, RefreshTokenRecord
├── Errors/                       # ErrorHandler
├── Exceptions/                   # HakiException, HttpException e derivadas
├── Console/                      # Application, Output, Commands/
└── Support/                      # Env, Dumper, Dump/
```

### 6.3 Estrutura recomendada para a aplicação do usuário

Não é distribuída: a documentação orienta o usuário a criá-la.

```text
minha-api/
├── app/{Controllers,Services,Repositories,Contracts,Models,Middleware}/
├── bootstrap/app.php
├── routes/api.php
├── public/index.php
├── storage/
├── .env
└── composer.json   # "autoload": { "psr-4": { "App\\": "app/" } }
```

Uma app nessa estrutura existe em `tests/Fixtures/app` e é testada de ponta a ponta.

### 6.4 Função `haki()`

Dump legível para depuração: tipos com cor e rótulo, arrays como lista/associativo, objetos com visibilidade (`+`, `#`, `-`), detecção de recursão, enums, closures e datas, arquivo:linha da chamada. HTML recolhível no navegador e árvore colorida no terminal. Retorna o valor recebido.

## 7. Fluxos

### 7.1 Requisição protegida

1. `run()` cria o `Request` a partir das superglobais.
2. O `Router` encontra a rota (ou responde 404/405).
3. O `Pipeline` executa os middlewares globais e de grupo.
4. `Authenticate` lê o Bearer token, valida formato, algoritmo, assinatura, claims e revogação.
5. Se válido, cria a `Identity` e a anexa ao `Request`; se não, responde 401.
6. `AuthorizeRole` compara o perfil; sem permissão, responde 403.
7. O `Resolver` instancia o controller e injeta os parâmetros.
8. O retorno do handler é convertido em `Response` e enviado uma vez.

### 7.2 Login e refresh

1. O cliente envia as credenciais; a aplicação as valida.
2. `$auth->login($id, $claims)` cria uma família, emite o access token (JWT) e o refresh token (aleatório, hash salvo no SQLite).
3. O access token expira (15 min por padrão); o cliente chama a rota de refresh com o refresh token.
4. `$auth->refresh()` consome o token de forma atômica e emite um novo par.
5. Se um refresh token consumido reaparecer, a família e os tokens do usuário são revogados.
6. `logout()` revoga o access token atual (`jti`) e a família do refresh token.

## 8. Segurança e limitações

**Garantias:**

- Algoritmo fixado na configuração; o token nunca escolhe o algoritmo.
- Comparação de assinatura em tempo constante (`hash_equals`).
- Segredo HS256 com no mínimo 32 bytes; chaves RSA com no mínimo 2048 bits; nenhum padrão inseguro.
- Refresh tokens armazenados apenas como hash, com rotação e detecção de reuso.
- 401 para falha de autenticação; 403 para falha de autorização.
- Mensagens genéricas para o cliente; detalhes só em dev.
- Sem tokens completos em logs.

**Limitações documentadas:**

- A assinatura prova a integridade do token, não que o usuário continua ativo. Mudanças de perfil ou bloqueios valem só após `logoutAll()` ou expiração do access token.
- O SQLite atende **um servidor**. Com vários servidores atrás de um load balancer, é preciso um `TokenStore` compartilhado (Redis, MySQL etc.).
- Rotação de segredo/chaves invalida todos os tokens existentes (não há suporte a múltiplas chaves ativas na v1).
- O Haki não valida senhas nem credenciais; isso é da aplicação.
- Limitação de tentativas de login fica a cargo da aplicação (ou de um middleware próprio).
- Use sempre HTTPS em produção.

## 9. Testes

PHPUnit, focado no comportamento real:

- **Rotas:** métodos, parâmetros, grupos, barra final, 404, 405 com `Allow`.
- **Request/Response:** JSON automático, corpo JSON inválido (400), retorno de array/string/null, envio único, falha de serialização.
- **Injeção:** `Request`, `Response`, `Auth`, parâmetros de rota por nome e tipo, ordem livre, erro claro para dependência não resolvível.
- **Middleware:** ordem de execução, interrupção, alteração da resposta.
- **JWT:** geração e validação HS256 e RS256; token expirado, adulterado, malformado, sem claims obrigatórios, `alg: none`, `alg` diferente do configurado, segredo curto, chave inválida.
- **Auth:** acesso sem token (401), token inválido (401), perfil errado (403), perfil certo (200), identidade no controller, logout, logoutAll, refresh com rotação, reuso de refresh token revoga a família.
- **SQLite:** criação automática do banco e das tabelas, idempotência, consumo atômico, limpeza de vencidos.
- **Erros:** formato padrão; produção sem detalhes internos.

## 10. Critérios de aceite da v1.0.0

- [ ] O exemplo de referência funciona com `php -S localhost:8000`.
- [ ] Todas as rotas, parâmetros e grupos funcionam; 404 e 405 corretos.
- [ ] `json()` / `responseJson()` e retornos automáticos seguem o contrato.
- [ ] Injeção automática funciona em closures e controllers.
- [ ] Middlewares executam na ordem documentada.
- [ ] JWT HS256 e RS256 geram e validam tokens corretamente.
- [ ] Tokens adulterados, expirados, revogados ou com algoritmo diferente são rejeitados.
- [ ] Refresh token com rotação e detecção de reuso funciona.
- [ ] Logout e logoutAll revogam os tokens.
- [ ] O SQLite é criado automaticamente na primeira requisição com auth.
- [ ] `->auth()` → 401 e `->role()` → 403 nos casos corretos.
- [ ] `Request::user()` só retorna identidades autenticadas.
- [ ] Produção não expõe detalhes internos.
- [ ] Comandos CLI funcionam.
- [ ] Todos os testes PHPUnit passam em PHP 8.2+.
- [ ] Instalação via Composer (path/VCS) validada.
- [ ] README completo, com exemplos executáveis.

## 11. Plano de desenvolvimento

1. **Fundação:** `Haki`, `Env`, `Request`, `Response`, `Router`, `Route`, grupos, `ErrorHandler`.
2. **Injeção e middleware:** `Resolver`, `Pipeline`, middleware global/grupo/rota.
3. **JWT:** `Jwt` (HS256 e RS256), `AuthConfig` com validação, testes de segurança.
4. **Storage:** `TokenStore`, `ArrayTokenStore`, `SqliteTokenStore` com criação automática.
5. **Auth:** `Auth` (login, refresh, logout, logoutAll), `Authenticate`, `AuthorizeRole`, `Identity`, `->auth()`, `->role()`.
6. **CLI:** `key:generate`, `auth:install`, `auth:clean`.
7. **Qualidade:** testes de integração e uma API de exemplo de ponta a ponta.
8. **Publicação:** README, `composer.json` final, tag `v1.0.0`, Packagist.

## 12. Roadmap (pós v1.0)

- Query builder leve sobre PDO.
- Múltiplas chaves ativas (rotação sem derrubar sessões) e ES256.
- Rate limiting integrado.
- Middleware de CORS pronto.
- Esqueleto `composer create-project shankscode/haki-app`.
- Comandos `make:controller` e `serve`.
- Geração de OpenAPI.
