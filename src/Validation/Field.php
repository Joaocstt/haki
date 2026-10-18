<?php

declare(strict_types=1);

namespace Haki\Validation;

use BackedEnum;
use Closure;
use DateTimeImmutable;

/**
 * A regra de um campo. Criada por Rule e configurada com métodos encadeados:
 *
 *     $rule->text()->length(3, 100)->optional()
 *
 * Todo campo é obrigatório, a menos que você chame optional().
 */
final class Field
{
    private bool $optional = false;

    private mixed $default = null;

    private ?int $minLength = null;

    private ?int $maxLength = null;

    private int|float|null $min = null;

    private int|float|null $max = null;

    /** @var list<string|int>|null */
    private ?array $allowed = null;

    private ?string $pattern = null;

    private ?string $patternMessage = null;

    private ?string $same = null;

    /** @var list<array{Closure(mixed): bool, string}> */
    private array $checks = [];

    private ?string $message = null;

    /**
     * @param array<string, mixed> $options item (list), fields (object), class (enum), format (date)
     *
     * @internal use os métodos de Rule
     */
    public function __construct(
        private readonly string $type,
        private readonly array $options = [],
    ) {
    }

    // ------------------------------------------------------------------ configuração

    /**
     * O campo pode faltar (ou vir vazio). Nesse caso, recebe o valor padrão.
     */
    public function optional(mixed $default = null): self
    {
        $this->optional = true;
        $this->default = $default;

        return $this;
    }

    /**
     * Quantidade de caracteres (textos) ou de itens (listas).
     *
     *     ->length(3, 100)   entre 3 e 100
     *     ->length(3)        pelo menos 3
     *     ->length(max: 100) no máximo 100
     */
    public function length(?int $min = null, ?int $max = null): self
    {
        $this->minLength = $min;
        $this->maxLength = $max;

        return $this;
    }

    /** Menor valor aceito (números). */
    public function min(int|float $value): self
    {
        $this->min = $value;

        return $this;
    }

    /** Maior valor aceito (números). */
    public function max(int|float $value): self
    {
        $this->max = $value;

        return $this;
    }

    /** Faixa de valores aceitos (números). */
    public function between(int|float $min, int|float $max): self
    {
        return $this->min($min)->max($max);
    }

    /** Só aceita um destes valores. */
    public function oneOf(string|int ...$values): self
    {
        $this->allowed = array_values($values);

        return $this;
    }

    /**
     * Precisa combinar com a expressão regular.
     *
     *     ->pattern('/^\d{5}-?\d{3}$/', 'Informe um CEP como 12345-678.')
     */
    public function pattern(string $regex, ?string $message = null): self
    {
        $this->pattern = $regex;
        $this->patternMessage = $message;

        return $this;
    }

    /**
     * Precisa ser igual a outro campo do mesmo nível (ex.: confirmação de senha).
     */
    public function same(string $field): self
    {
        $this->same = $field;

        return $this;
    }

    /**
     * Regra própria: a função recebe o valor já convertido e retorna true se estiver certo.
     *
     *     ->check(fn ($cpf) => Cpf::valido($cpf), 'Informe um CPF válido.')
     */
    public function check(Closure $rule, string $message): self
    {
        $this->checks[] = [$rule, $message];

        return $this;
    }

    /**
     * Troca todas as mensagens de erro deste campo por uma só.
     */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    // ------------------------------------------------------------------ uso interno

    /** @internal */
    public function type(): string
    {
        return $this->type;
    }

    /** @internal */
    public function option(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    /** @internal */
    public function isOptional(): bool
    {
        return $this->optional;
    }

    /** @internal */
    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /** @internal */
    public function customMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Valida a quantidade de itens de uma lista.
     *
     * @internal
     * @param array<mixed> $items
     */
    public function checkListLength(array $items, string $subject): ?string
    {
        return $this->lengthError(count($items), $subject, 'item', 'itens');
    }

    /**
     * Converte e valida um valor simples (não lista, não objeto).
     *
     * @internal
     * @param array<string, mixed> $siblings os outros campos do mesmo nível (para same())
     * @return array{mixed, list<string>} valor convertido e mensagens de erro
     */
    public function validateValue(mixed $value, string $subject, array $siblings): array
    {
        [$converted, $typeError] = $this->convert($value, $subject);

        if ($typeError !== null) {
            return [null, [$typeError]];
        }

        $errors = [];

        if ($this->type === 'text' || $this->type === 'email' || $this->type === 'url') {
            $error = $this->lengthError(mb_strlen((string) $converted), $subject, 'caractere', 'caracteres');

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if (is_int($converted) || is_float($converted)) {
            if ($this->min !== null && $converted < $this->min) {
                $errors[] = "{$subject} precisa ser no mínimo {$this->format($this->min)}.";
            }

            if ($this->max !== null && $converted > $this->max) {
                $errors[] = "{$subject} pode ser no máximo {$this->format($this->max)}.";
            }
        }

        if ($this->allowed !== null) {
            $match = $this->matchAllowed($converted);

            if ($match === null) {
                $errors[] = "{$subject} precisa ser um destes: " . implode(', ', $this->allowed) . '.';
            } else {
                $converted = $match;
            }
        }

        if ($this->pattern !== null && is_scalar($converted) && preg_match($this->pattern, (string) $converted) !== 1) {
            $errors[] = $this->patternMessage ?? "{$subject} está em um formato inválido.";
        }

        if ($this->same !== null && !$this->sameAs($value, $siblings[$this->same] ?? null)) {
            $errors[] = "{$subject} precisa ser igual ao campo " . str_replace('_', ' ', $this->same) . '.';
        }

        if ($errors === []) {
            foreach ($this->checks as [$rule, $message]) {
                if (!$rule($converted)) {
                    $errors[] = $message;
                }
            }
        }

        return [$converted, $errors];
    }

    /**
     * Formatos prontos: tipo => [método de Formats, descrição usada na mensagem de erro].
     */
    private const FORMATS = [
        'cep' => ['cep', 'um CEP válido (ex.: 12345-678)'],
        'cpf' => ['cpf', 'um CPF válido'],
        'cnpj' => ['cnpj', 'um CNPJ válido'],
        'cpfOrCnpj' => ['cpfOrCnpj', 'um CPF ou CNPJ válido'],
        'phone' => ['phone', 'um telefone válido com DDD (ex.: (11) 98765-4321)'],
        'mobile' => ['mobile', 'um celular válido com DDD (ex.: (11) 98765-4321)'],
        'plate' => ['plate', 'uma placa válida (ex.: ABC1D23)'],
        'uf' => ['uf', 'uma sigla de estado válida (ex.: SP)'],
        'time' => ['time', 'um horário válido (ex.: 18:30)'],
        'slug' => ['slug', 'um slug válido (letras minúsculas, números e hífens)'],
        'hexColor' => ['hexColor', 'uma cor hexadecimal válida (ex.: #ff8800)'],
    ];

    /**
     * @return array{mixed, string|null}
     */
    private function convert(mixed $value, string $subject): array
    {
        if (isset(self::FORMATS[$this->type])) {
            [$method, $description] = self::FORMATS[$this->type];
            $normalized = is_string($value) || is_int($value) ? Formats::$method((string) $value) : null;

            return $normalized !== null ? [$normalized, null] : [null, "{$subject} precisa ser {$description}."];
        }

        return match ($this->type) {
            'text' => is_string($value) || is_int($value) || is_float($value)
                ? [(string) $value, null]
                : [null, "{$subject} precisa ser um texto."],
            'integer' => $this->toInteger($value, $subject),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? [$value + 0, null]
                : [null, "{$subject} precisa ser um número."],
            'boolean' => $this->toBoolean($value, $subject),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? [$value, null]
                : [null, "{$subject} precisa ser um e-mail válido."],
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1
                ? [$value, null]
                : [null, "{$subject} precisa ser uma URL válida (começando com http:// ou https://)."],
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
                ? [strtolower($value), null]
                : [null, "{$subject} precisa ser um UUID válido."],
            'date' => $this->toDate($value, $subject),
            'enum' => $this->toEnum($value, $subject),
            default => [$value, null],
        };
    }

    /**
     * @return array{int|null, string|null}
     */
    private function toInteger(mixed $value, string $subject): array
    {
        if (is_int($value)) {
            return [$value, null];
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return [(int) $value, null];
        }

        if (is_float($value) && floor($value) === $value) {
            return [(int) $value, null];
        }

        return [null, "{$subject} precisa ser um número inteiro."];
    }

    /**
     * @return array{bool|null, string|null}
     */
    private function toBoolean(mixed $value, string $subject): array
    {
        if (is_bool($value)) {
            return [$value, null];
        }

        $text = is_scalar($value) ? strtolower((string) $value) : '';

        return match ($text) {
            '1', 'true', 'on', 'yes', 'sim' => [true, null],
            '0', 'false', 'off', 'no', 'nao', 'não' => [false, null],
            default => [null, "{$subject} precisa ser verdadeiro ou falso."],
        };
    }

    /**
     * @return array{DateTimeImmutable|null, string|null}
     */
    private function toDate(mixed $value, string $subject): array
    {
        $format = (string) ($this->options['format'] ?? 'Y-m-d');

        if (is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return [$date, null];
            }
        }

        $example = (new DateTimeImmutable('2026-12-31 18:30:00'))->format($format);

        return [null, "{$subject} precisa ser uma data válida (ex.: {$example})."];
    }

    /**
     * @return array{BackedEnum|null, string|null}
     */
    private function toEnum(mixed $value, string $subject): array
    {
        /** @var class-string<BackedEnum> $class */
        $class = $this->options['class'];

        if (is_int($value) || is_string($value)) {
            foreach ($class::cases() as $case) {
                if ((string) $case->value === (string) $value) {
                    return [$case, null];
                }
            }
        }

        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $class::cases());

        return [null, "{$subject} precisa ser um destes: " . implode(', ', $values) . '.'];
    }

    private function matchAllowed(mixed $value): string|int|null
    {
        if (!is_scalar($value)) {
            return null;
        }

        foreach ($this->allowed ?? [] as $allowed) {
            if ((string) $allowed === (string) $value) {
                return $allowed;
            }
        }

        return null;
    }

    private function sameAs(mixed $value, mixed $other): bool
    {
        if (is_string($other)) {
            $other = trim($other);
        }

        return is_scalar($value) && is_scalar($other) && (string) $value === (string) $other;
    }

    private function lengthError(int $length, string $subject, string $singular, string $plural): ?string
    {
        $min = $this->minLength;
        $max = $this->maxLength;
        $word = static fn (int $n): string => $n . ' ' . ($n === 1 ? $singular : $plural);

        if ($min !== null && $max !== null && ($length < $min || $length > $max)) {
            return $min === $max
                ? "{$subject} precisa ter exatamente {$word($min)}."
                : "{$subject} precisa ter entre {$min} e {$word($max)}.";
        }

        if ($min !== null && $max === null && $length < $min) {
            return "{$subject} precisa ter pelo menos {$word($min)}.";
        }

        if ($max !== null && $min === null && $length > $max) {
            return "{$subject} pode ter no máximo {$word($max)}.";
        }

        return null;
    }

    private function format(int|float $number): string
    {
        return is_float($number) ? rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',') : (string) $number;
    }
}
