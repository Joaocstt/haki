<?php

declare(strict_types=1);

namespace Haki\Support;

use Composer\Autoload\ClassLoader;

/**
 * Descobre quais classes existem em um namespace, olhando as pastas do autoload PSR-4 do Composer.
 * Usado para sugerir nomes ("você quis dizer...") e pelo comando haki check.
 */
final class ClassFinder
{
    /**
     * Classes e interfaces declaradas diretamente no namespace (sem subpastas).
     *
     * @return list<string>
     */
    public static function inNamespace(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        $classes = [];

        foreach (self::directoriesFor($namespace) as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $classes[] = $namespace . '\\' . basename($file, '.php');
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * A classe do namespace com o nome mais parecido, ou null se nenhuma for parecida o bastante.
     */
    public static function similar(string $class): ?string
    {
        $class = ltrim($class, '\\');
        $position = strrpos($class, '\\');
        $namespace = $position === false ? '' : substr($class, 0, $position);
        $short = $position === false ? $class : substr($class, $position + 1);

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::inNamespace($namespace) as $candidate) {
            $candidateShort = substr($candidate, strrpos($candidate, '\\') + 1);

            if (strcasecmp($candidateShort, $short) === 0 && $candidate !== $class) {
                return $candidate; // só muda maiúscula/minúscula
            }

            $distance = levenshtein(strtolower($short), strtolower($candidateShort));

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($short), 4)) ? $best : null;
    }

    /**
     * @return list<string>
     */
    private static function directoriesFor(string $namespace): array
    {
        if ($namespace === '' || !class_exists(ClassLoader::class, false)) {
            return [];
        }

        $directories = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
                $prefix = rtrim($prefix, '\\');

                if ($namespace !== $prefix && !str_starts_with($namespace . '\\', $prefix . '\\')) {
                    continue;
                }

                $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($namespace, strlen($prefix)));

                foreach ($paths as $path) {
                    $directory = rtrim($path, '/\\') . $relative;

                    if (is_dir($directory)) {
                        $directories[] = realpath($directory) ?: $directory;
                    }
                }
            }
        }

        return array_values(array_unique($directories));
    }
}
