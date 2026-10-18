<?php

declare(strict_types=1);

namespace Haki\Validation;

use Haki\Auth\Identity;
use Haki\Http\Request;
use LogicException;

/**
 * Classe de validação. Peça-a no controller e ela valida a requisição antes do seu código rodar:
 *
 *     final class CriarClienteRequest extends FormRequest
 *     {
 *         public function rules(Rule $rule): array
 *         {
 *             return ['nome' => $rule->text()->length(3, 100), 'email' => $rule->email()];
 *         }
 *     }
 *
 *     public function store(CriarClienteRequest $request): array
 *     {
 *         $request->nome;          // valor validado
 *         $request->validated();   // todos os campos validados
 *     }
 *
 * Se algo estiver inválido, o cliente recebe 422 com as mensagens por campo.
 */
abstract class FormRequest
{
    private ?Request $request = null;

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, list<string>> */
    private array $failures = [];

    /**
     * As regras de cada campo.
     *
     * @return array<string, Field|array<mixed>>
     */
    abstract public function rules(Rule $rule): array;

    /**
     * Nomes amigáveis usados nas mensagens: ['email' => 'e-mail', 'endereco.cep' => 'CEP'].
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [];
    }

    /**
     * Ajusta os dados antes de validar (opcional). Ex.: tirar a máscara do CPF.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data): array
    {
        return $data;
    }

    /**
     * Regras que precisam de lógica (opcional). Roda só se as regras de rules() passaram.
     * Use $this->fail('campo', 'mensagem') para marcar um erro.
     *
     * @param array<string, mixed> $data dados já validados
     */
    protected function check(array $data): void
    {
    }

    /**
     * Marca um erro em um campo. Use dentro de check().
     */
    protected function fail(string $field, string $message): void
    {
        $this->failures[$field][] = $message;
    }

    // ------------------------------------------------------------------ leitura

    /**
     * Todos os campos validados e convertidos. Campos não declarados em rules() nunca aparecem.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Um campo validado. Aceita notação de ponto: get('endereco.cidade').
     */
    public function get(string $field, mixed $default = null): mixed
    {
        $value = $this->validated;

        foreach (explode('.', $field) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Só os campos informados.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        return array_intersect_key($this->validated, array_flip($fields));
    }

    /**
     * A requisição original (para cabeçalhos, IP, parâmetros de rota...).
     */
    public function request(): Request
    {
        return $this->request ?? throw new LogicException('A requisição ainda não foi validada.');
    }

    /**
     * Atalho para $request->request()->user().
     */
    public function user(): ?Identity
    {
        return $this->request()->user();
    }

    /**
     * Acesso direto: $request->nome.
     */
    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->validated)) {
            throw new LogicException(
                "O campo \"{$name}\" não existe em " . static::class . '. Declare-o em rules() para poder usá-lo.',
            );
        }

        return $this->validated[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->validated[$name]);
    }

    // ------------------------------------------------------------------ interno

    /**
     * Valida a requisição. Chamado pelo Haki antes do controller.
     *
     * Os dados vêm da query string e do corpo juntos (o corpo tem prioridade).
     *
     * @internal
     * @throws ValidationException
     */
    public function validateRequest(Request $request): void
    {
        $this->request = $request;
        $this->failures = [];

        /** @var array<string, mixed> $query */
        $query = $request->query();
        $data = $this->prepare(array_replace($query, $request->all()));

        $this->validated = (new Validator())->validate($data, $this->rules(new Rule()), $this->labels());
        $this->check($this->validated);

        if ($this->failures !== []) {
            throw new ValidationException($this->failures);
        }
    }
}
