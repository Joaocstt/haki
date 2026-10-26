<?php

declare(strict_types=1);

namespace Haki;

use Closure;
use Elo\Conexao;
use ErrorException;
use Haki\Auth\Auth;
use Haki\Auth\AuthConfig;
use Haki\Auth\Middleware\Authenticate;
use Haki\Auth\Middleware\AuthorizeRole;
use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Auth\Storage\TokenStore;
use Haki\Auth\Tentativas\ArmazenamentoDeTentativas;
use Haki\Auth\Tentativas\PdoArmazenamentoDeTentativas;
use Haki\Middleware\LimitarRequisicoes;
use Haki\Container\Container;
use Haki\Container\Invoker;
use Haki\Errors\ErrorHandler;
use Haki\Exceptions\HakiException;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Http\ResponseConverter;
use Haki\Http\ResponseEmitter;
use Haki\Middleware\MiddlewareInterface;
use Haki\Middleware\Pipeline;
use Haki\Routing\Route;
use Haki\Routing\RouteGroup;
use Haki\Routing\Router;
use Haki\Support\Env;
use Throwable;

/**
 * Ponto de entrada da aplicação.
 *
 *     $app = new Haki(__DIR__);
 *     $app->get('/ola', function () {
 *         return ['mensagem' => 'Olá!'];
 *     });
 *     $app->run();
 */
final class Haki
{
    public const VERSION = '1.7.0';

    private readonly string $basePath;

    private readonly bool $debug;

    private readonly Container $container;

    private readonly Router $router;

    private readonly Invoker $invoker;

    private readonly Pipeline $pipeline;

    private readonly ErrorHandler $errors;

    private readonly ResponseConverter $converter;

    /** @var list<MiddlewareInterface|Closure|class-string> */
    private array $middleware = [];

    private ?AuthConfig $authConfig = null;

    /**
     * @param string|null $basePath pasta raiz do projeto (onde ficam .env e storage/)
     * @param bool|null   $debug    força o modo de desenvolvimento; por padrão lê APP_ENV
     */
    public function __construct(?string $basePath = null, ?bool $debug = null)
    {
        $this->basePath = rtrim($basePath ?? (string) getcwd(), '/\\');

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        // Caminho relativo de SQLite (DB_DATABASE=database/app.sqlite) parte da raiz, não de public/.
        Conexao::raizDoProjeto($this->basePath);

        $this->debug = $debug ?? in_array(strtolower((string) Env::get('APP_ENV', 'production')), ['development', 'dev', 'local'], true);
        $this->container = new Container();
        $this->router = new Router();
        $this->invoker = new Invoker($this->container);
        $this->pipeline = new Pipeline($this->container);
        $this->errors = new ErrorHandler($this->debug);
        $this->converter = new ResponseConverter();

        $this->container
            ->instance(self::class, $this)
            ->instance(Container::class, $this->container)
            ->instance(Router::class, $this->router);

        $this->registerAuthServices();
        $this->registerLimitServices();
    }

    // ------------------------------------------------------------------ rotas

    /** @param callable|array{class-string, string}|class-string $handler */
    public function get(string $path, mixed $handler): Route
    {
        return $this->router->add('GET', $path, $handler);
    }

    /** @param callable|array{class-string, string}|class-string $handler */
    public function post(string $path, mixed $handler): Route
    {
        return $this->router->add('POST', $path, $handler);
    }

    /** @param callable|array{class-string, string}|class-string $handler */
    public function put(string $path, mixed $handler): Route
    {
        return $this->router->add('PUT', $path, $handler);
    }

    /** @param callable|array{class-string, string}|class-string $handler */
    public function patch(string $path, mixed $handler): Route
    {
        return $this->router->add('PATCH', $path, $handler);
    }

    /** @param callable|array{class-string, string}|class-string $handler */
    public function delete(string $path, mixed $handler): Route
    {
        return $this->router->add('DELETE', $path, $handler);
    }

    /**
     * Agrupa rotas com um prefixo comum.
     *
     * @param callable(self): void $routes
     */
    public function group(string $prefix, callable $routes): RouteGroup
    {
        return $this->router->group($prefix, fn () => $routes($this));
    }

    /**
     * Middlewares globais: rodam em todas as requisições, na ordem em que foram registrados.
     */
    public function middleware(MiddlewareInterface|Closure|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);

        return $this;
    }

    /**
     * Carrega um arquivo de rotas. Dentro dele, a variável $app é esta aplicação:
     *
     *     // routes/api.php
     *     /** @var Haki\Haki $app *\/
     *     $app->get('/', [HomeController::class, 'index']);
     *
     * Arquivos no formato antigo, que retornam function (Haki $app) { ... }, também funcionam.
     */
    public function routes(string $file): self
    {
        $path = preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\)#', $file) === 1 ? $file : $this->basePath($file);

        if (!is_file($path)) {
            throw new HakiException(
                "Arquivo de rotas não encontrado: {$path}.",
                'Confira o caminho passado para $app->routes(). O padrão é routes/api.php.',
            );
        }

        $result = (static function (self $app, string $__file): mixed {
            return require $__file;
        })($this, $path);

        if ($result instanceof Closure) {
            $result($this);
        }

        return $this;
    }

    /**
     * Passa a exigir tipagem no código do projeto: se um controller, service, repository,
     * request ou middleware tiver parâmetro, retorno ou propriedade sem tipo (ou faltar
     * declare(strict_types=1)), a requisição responde 500 dizendo o que corrigir.
     */
    public function requireTypes(): self
    {
        $this->container->requireTypes();

        return $this;
    }

    // ------------------------------------------------------------------ configuração

    /**
     * Configura a autenticação JWT. Sem esta chamada, as variáveis JWT_* do .env são usadas.
     *
     * @param array<string, mixed> $options
     */
    public function auth(array $options): self
    {
        $this->authConfig = AuthConfig::fromArray($options, $this->basePath);
        $this->registerAuthServices();

        return $this;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    // ------------------------------------------------------------------ execução

    /**
     * Processa a requisição atual e envia a resposta ao cliente.
     */
    public function run(): void
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            (new ResponseEmitter())->emit($this->handle(Request::capture()));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Processa uma requisição e devolve a resposta, sem enviar. Ideal para testes.
     */
    public function handle(Request $request): Response
    {
        try {
            return $this->pipeline->process($request, $this->middleware, fn (Request $r): Response => $this->dispatch($r));
        } catch (Throwable $e) {
            return $this->errors->render($e);
        }
    }

    private function dispatch(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method(), $request->path());
            $route = $match->route;

            $request->setParams($match->params);
            $this->container->instance(Request::class, $request);

            return $this->pipeline->process(
                $request,
                $this->routeMiddleware($route),
                fn (Request $r): Response => $this->converter->convert($this->invoker->call($route->getHandler(), $r)),
            );
        } catch (Throwable $e) {
            return $this->errors->render($e);
        }
    }

    /**
     * Ordem: grupo → limite → autenticação → perfis → rota.
     *
     * @return list<MiddlewareInterface|Closure|class-string>
     */
    private function routeMiddleware(Route $route): array
    {
        $middleware = $route->getGroupMiddleware();

        if (($limite = $route->getLimite()) !== null) {
            $middleware[] = new LimitarRequisicoes(
                $this->container->get(ArmazenamentoDeTentativas::class),
                $limite[0],
                $limite[1],
                $route->getMethod() . ' ' . $route->getPath(),
            );
        }

        if ($route->requiresAuth()) {
            $middleware[] = Authenticate::class;
        }

        foreach ($route->getRoles() as $roles) {
            $middleware[] = new AuthorizeRole(...$roles);
        }

        return [...$middleware, ...$route->getMiddleware()];
    }

    /**
     * Armazenamento dos limites por rota. Padrão: SQLite em storage/haki.sqlite (o mesmo arquivo
     * padrão dos tokens). Para vários servidores, troque por um banco compartilhado:
     *
     *     $app->container()->singleton(ArmazenamentoDeTentativas::class,
     *         fn () => new PdoArmazenamentoDeTentativas($pdoCompartilhado));
     */
    private function registerLimitServices(): void
    {
        $this->container->singleton(ArmazenamentoDeTentativas::class, function (): ArmazenamentoDeTentativas {
            $arquivo = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'haki.sqlite';

            if (!is_dir(dirname($arquivo))) {
                @mkdir(dirname($arquivo), 0775, true);
            }

            $armazenamento = new PdoArmazenamentoDeTentativas(new \PDO('sqlite:' . $arquivo, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]));
            $armazenamento->criarTabela();

            return $armazenamento;
        });
    }

    private function registerAuthServices(): void
    {
        $this->container
            ->singleton(AuthConfig::class, fn (): AuthConfig => $this->authConfig ??= AuthConfig::fromEnv($this->basePath))
            ->singleton(TokenStore::class, function (Container $c): TokenStore {
                $config = $c->get(AuthConfig::class);

                return $config->storage ?? new SqliteTokenStore($config->database);
            })
            ->singleton(Auth::class, fn (Container $c): Auth => new Auth($c->get(AuthConfig::class), $c->get(TokenStore::class)));
    }
}
