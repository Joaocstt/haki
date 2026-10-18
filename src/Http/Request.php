<?php

declare(strict_types=1);

namespace Haki\Http;

use Haki\Auth\Identity;
use Haki\Exceptions\HttpException;
use JsonException;

/**
 * Requisição HTTP recebida pela aplicação.
 *
 * Todos os valores vêm do cliente e devem ser tratados como não confiáveis.
 */
class Request
{
    /** @var array<string, string> cabeçalhos com nomes em minúsculas */
    private array $headers = [];

    /** @var array<string, mixed>|null corpo decodificado (lazy) */
    private ?array $parsedBody = null;

    /** @var array<string, string> parâmetros da rota encontrada */
    private array $params = [];

    private ?Identity $user = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, mixed>  $form    dados de formulário ($_POST)
     * @param array<string, mixed>  $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query = [],
        array $headers = [],
        private readonly string $body = '',
        private readonly array $form = [],
        private readonly array $server = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * Cria a requisição a partir das variáveis globais do PHP.
     */
    public static function capture(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        // Alguns servidores (Apache + CGI) removem o Authorization das variáveis HTTP_*.
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            $_POST,
            $_SERVER,
        );
    }

    /**
     * Atalho para criar requisições em testes.
     *
     * @param array<string, mixed>  $data    corpo enviado como JSON
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $uri, array $data = [], array $headers = []): self
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $body = '';

        if ($data !== []) {
            $body = json_encode($data, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }

        /** @var array<string, mixed> $query */
        return new self(strtoupper($method), $uri, $query, $headers, $body);
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * Caminho sem query string e sem barra final: "/usuarios/10".
     */
    public function path(): string
    {
        $path = rawurldecode((string) parse_url($this->uri, PHP_URL_PATH));
        $path = '/' . trim($path, '/');

        return $path;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Parâmetro da rota, ex.: {id} em "/usuarios/{id}".
     */
    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Valor da query string (?pagina=2). Sem nome, devolve todos.
     */
    public function query(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->query;
        }

        return $this->query[$name] ?? $default;
    }

    /**
     * Valor do corpo (JSON ou formulário). Aceita notação de ponto: input('endereco.cidade').
     */
    public function input(string $name, mixed $default = null): mixed
    {
        $value = $this->all();

        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Verifica se o corpo contém o campo.
     */
    public function has(string $name): bool
    {
        return $this->input($name, $this) !== $this;
    }

    /**
     * Corpo completo da requisição como array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        if (!$this->isJson()) {
            return $this->parsedBody = $this->form;
        }

        if (trim($this->body) === '') {
            return $this->parsedBody = [];
        }

        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpException(400, 'JSON inválido no corpo da requisição', hint: $e->getMessage(), previous: $e);
        }

        return $this->parsedBody = is_array($data) ? $data : ['value' => $data];
    }

    /**
     * Apenas os campos informados.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        $type = strtolower((string) $this->header('Content-Type'));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    /**
     * Token do cabeçalho "Authorization: Bearer <token>".
     */
    public function bearerToken(): ?string
    {
        $header = (string) $this->header('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function ip(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    /**
     * Usuário autenticado (preenchido apenas pelo middleware de autenticação após validar o token).
     */
    public function user(): ?Identity
    {
        return $this->user;
    }

    /**
     * @internal usado pelo roteador
     * @param array<string, string> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * @internal usado pelo middleware Authenticate
     */
    public function setUser(?Identity $user): void
    {
        $this->user = $user;
    }
}
