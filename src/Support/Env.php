<?php

declare(strict_types=1);

namespace Haki\Support;

/**
 * Leitor simples de arquivos .env e acesso às variáveis de ambiente.
 *
 * Variáveis já definidas no ambiente (servidor, Docker, phpdotenv) nunca são sobrescritas.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            $name = preg_replace('/^export\s+/', '', $name) ?? $name;

            if ($name === '' || self::raw($name) !== null) {
                continue;
            }

            $value = self::clean($value);

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * Lê uma variável. "true", "false" e "null" são convertidos.
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        $value = self::raw($name);

        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '' => $default,
            default => $value,
        };
    }

    private static function raw(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }

    private static function clean(string $value): string
    {
        if (preg_match('/^"(.*)"$/s', $value, $m) === 1) {
            return stripcslashes($m[1]);
        }

        if (preg_match("/^'(.*)'$/s", $value, $m) === 1) {
            return $m[1];
        }

        // Remove comentário no fim da linha: VALOR=abc # comentário
        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }
}
