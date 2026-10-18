<?php

declare(strict_types=1);

namespace Haki\Http;

use Haki\Exceptions\HakiException;
use JsonException;

/**
 * Resposta HTTP.
 *
 * Contrato: os métodos só montam a resposta e devolvem o próprio objeto.
 * Quem envia para o cliente é o Haki::run(), uma única vez.
 */
class Response
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    /**
     * Resposta JSON.
     *
     *     return $response->json(['mensagem' => 'Criado'], 201);
     */
    public function json(mixed $data, int $status = 200): static
    {
        try {
            $this->body = json_encode($data, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new HakiException(
                'Não foi possível converter a resposta para JSON: ' . $e->getMessage(),
                'Verifique se os dados não contêm recursos, textos com codificação inválida ou referências circulares.',
                previous: $e,
            );
        }

        $this->status = $status;

        return $this->header('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Alias de json(), mantido por compatibilidade com o nome original.
     */
    public function responseJson(mixed $data, int $status = 200): static
    {
        return $this->json($data, $status);
    }

    /**
     * Resposta em texto puro.
     */
    public function text(string $text, int $status = 200): static
    {
        $this->body = $text;
        $this->status = $status;

        return $this->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$this->normalizeName($name)] = $value;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->normalizeName($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Corpo JSON decodificado — útil em testes.
     */
    public function getJson(): mixed
    {
        return json_decode($this->body, true);
    }

    private function normalizeName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
