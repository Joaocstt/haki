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
     * Todas as classes do projeto: as pastas PSR-4 que ficam dentro de $basePath (fora do vendor),
     * incluindo subpastas. Ex.: tudo em app/ para o prefixo App\.
     *
     * @return list<string>
     */
    public static function inProject(string $basePath): array
    {
        $base = realpath($basePath);

        if ($base === false || !class_exists(ClassLoader::class, false)) {
            return [];
        }

        $base = str_replace('\\', '/', $base);
        $classes = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
                foreach ($paths as $path) {
                    $directory = realpath($path);

                    if ($directory === false) {
                        continue;
                    }

                    $normalized = str_replace('\\', '/', $directory);

                    if (!str_starts_with($normalized . '/', $base . '/') || str_contains($normalized, '/vendor/')) {
                        continue;
                    }

                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

                    foreach ($files as $file) {
                        if ($file->getExtension() !== 'php') {
                            continue;
                        }

                        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($normalized) + 1, -4);
                        $classes[] = $prefix . str_replace('/', '\\', $relative);
                    }
                }
            }
        }

        sort($classes);

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
