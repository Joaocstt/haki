<?php

declare(strict_types=1);

namespace Haki\Validation;

/**
 * Aplica as regras aos dados e devolve só os campos validados, já convertidos.
 */
final class Validator
{
    /**
     * @param array<string, mixed>                $data
     * @param array<string, Field|array<mixed>>   $rules
     * @param array<string, string>               $labels nomes amigáveis: ['email' => 'e-mail']
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $validated = $this->object($data, $rules, '', $labels, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * @param array<mixed>                      $data
     * @param array<string, Field|array<mixed>> $rules
     * @param array<string, string>             $labels
     * @param array<string, list<string>>       $errors
     * @return array<string, mixed>
     */
    private function object(array $data, array $rules, string $prefix, array $labels, array &$errors): array
    {
        $validated = [];

        foreach ($rules as $key => $rule) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $field = $rule instanceof Field ? $rule : new Field('object', ['fields' => $rule]);
            $present = array_key_exists($key, $data);

            [$ok, $value] = $this->field($field, $present ? $data[$key] : null, $path, $labels, $data, $errors);

            if ($ok) {
                $validated[$key] = $value;
            }
        }

        return $validated;
    }

    /**
     * @param array<string, string>       $labels
     * @param array<mixed>                $siblings
     * @param array<string, list<string>> $errors
     * @return array{bool, mixed} [incluir no resultado?, valor]
     */
    private function field(Field $field, mixed $value, string $path, array $labels, array $siblings, array &$errors): array
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        $subject = $this->subject($path, $labels);

        if ($value === null || $value === '') {
            if ($field->isOptional()) {
                return [true, $field->defaultValue()];
            }

            $this->addErrors($errors, $path, $field, ["{$subject} é obrigatório."]);

            return [false, null];
        }

        if ($field->type() === 'object') {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                $this->addErrors($errors, $path, $field, ["{$subject} precisa ser um objeto."]);

                return [false, null];
            }

            /** @var array<string, Field|array<mixed>> $fields */
            $fields = $field->option('fields');

            return [true, $this->object($value, $fields, $path, $labels, $errors)];
        }

        if ($field->type() === 'list') {
            return $this->list($field, $value, $path, $subject, $labels, $errors);
        }

        [$converted, $messages] = $field->validateValue($value, $subject, $siblings);

        if ($messages !== []) {
            $this->addErrors($errors, $path, $field, $messages);

            return [false, null];
        }

        return [true, $converted];
    }

    /**
     * @param array<string, string>       $labels
     * @param array<string, list<string>> $errors
     * @return array{bool, mixed}
     */
    private function list(Field $field, mixed $value, string $path, string $subject, array $labels, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->addErrors($errors, $path, $field, ["{$subject} precisa ser uma lista."]);

            return [false, null];
        }

        $lengthError = $field->checkListLength($value, $subject);

        if ($lengthError !== null) {
            $this->addErrors($errors, $path, $field, [$lengthError]);

            return [false, null];
        }

        $rule = $field->option('item');
        $item = $rule instanceof Field ? $rule : new Field('object', ['fields' => $rule]);
        $validated = [];
        $valid = true;

        foreach ($value as $index => $element) {
            [$ok, $converted] = $this->field($item, $element, $path . '.' . $index, $labels, $value, $errors);
            $valid = $valid && $ok;
            $validated[] = $converted;
        }

        return [$valid, $validated];
    }

    /**
     * @param array<string, list<string>> $errors
     * @param list<string>                $messages
     */
    private function addErrors(array &$errors, string $path, Field $field, array $messages): void
    {
        $custom = $field->customMessage();

        foreach ($custom !== null ? [$custom] : $messages as $message) {
            $errors[$path][] = $message;
        }
    }

    /**
     * "O campo e-mail", "O campo cidade", "O item 2 de tags".
     *
     * @param array<string, string> $labels
     */
    private function subject(string $path, array $labels): string
    {
        $segments = explode('.', $path);
        $last = (string) array_pop($segments);

        if (ctype_digit($last) && $segments !== []) {
            return 'O item ' . ((int) $last + 1) . ' de ' . $this->label(implode('.', $segments), $labels);
        }

        return 'O campo ' . $this->label($path, $labels);
    }

    /**
     * @param array<string, string> $labels
     */
    private function label(string $path, array $labels): string
    {
        if (isset($labels[$path])) {
            return $labels[$path];
        }

        $segments = explode('.', $path);
        $last = (string) end($segments);

        // Item de lista sem nome próprio: usa o nome da lista ("item 2 de tags").
        if (ctype_digit($last) && count($segments) > 1) {
            array_pop($segments);

            return 'item ' . ((int) $last + 1) . ' de ' . $this->label(implode('.', $segments), $labels);
        }

        return $labels[$last] ?? str_replace('_', ' ', $last);
    }
}
