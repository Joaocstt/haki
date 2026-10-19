<?php

declare(strict_types=1);

namespace Haki\Support;

use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Confere se o código da aplicação está tipado. Usado por $app->requireTypes() e pelo haki check.
 *
 * Em cada classe do projeto (não do vendor), exige:
 *  - declare(strict_types=1) no arquivo;
 *  - tipo em todos os parâmetros dos métodos;
 *  - tipo de retorno em todos os métodos (menos __construct e __destruct);
 *  - tipo em todas as propriedades.
 *
 * Em funções anônimas (rotas e middlewares), exige tipo nos parâmetros.
 */
final class TypeChecker
{
    /** @var array<string, list<string>> resultado por classe, para conferir cada uma só uma vez */
    private array $cache = [];

    /**
     * Problemas de tipagem da classe. Lista vazia quando está tudo certo,
     * ou quando a classe não é do projeto (vendor, PHP, Haki).
     *
     * @return list<string>
     */
    public function checkClass(string $class): array
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false || !$this->belongsToApp($class, $file)) {
            return $this->cache[$class] = [];
        }

        $problems = [];
        $short = $reflection->getShortName();

        if (!$this->hasStrictTypes($file)) {
            $problems[] = basename($file) . ': falta declare(strict_types=1); logo depois do <?php.';
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class || $property->isPromoted()) {
                continue;
            }

            if (!$property->hasType()) {
                $problems[] = "{$short}::\${$property->getName()}: propriedade sem tipo.";
            }
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->getFileName() !== $file) {
                continue; // herdado ou vindo de trait
            }

            $label = "{$short}::{$method->getName()}()";
            array_push($problems, ...$this->checkFunction($method, $label, !$this->isExemptFromReturn($method)));
        }

        return $this->cache[$class] = $problems;
    }

    /**
     * Problemas de tipagem de uma função ou método.
     *
     * @return list<string>
     */
    public function checkFunction(ReflectionFunctionAbstract $function, string $label, bool $requireReturn): array
    {
        $problems = [];
        $line = $function->getStartLine();
        $where = $line !== false ? " (linha {$line})" : '';

        foreach ($function->getParameters() as $param) {
            if (!$param->hasType()) {
                $problems[] = "{$label}: parâmetro \${$param->getName()} sem tipo{$where}.";
            }
        }

        if ($requireReturn && !$function->hasReturnType()) {
            $problems[] = "{$label}: falta o tipo de retorno{$where}.";
        }

        return $problems;
    }

    private function isExemptFromReturn(ReflectionMethod $method): bool
    {
        return in_array($method->getName(), ['__construct', '__destruct', '__clone'], true);
    }

    /**
     * Só confere o código do projeto: ignora o Haki, o vendor e as classes do próprio PHP.
     */
    private function belongsToApp(string $class, string $file): bool
    {
        if (str_starts_with($class, 'Haki\\') && !str_starts_with($class, 'Haki\\Tests\\')) {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);

        return !str_contains($normalized, '/vendor/');
    }

    private function hasStrictTypes(string $file): bool
    {
        $head = (string) file_get_contents($file, false, null, 0, 2048);

        return preg_match('/^\s*<\?php\s+(?:\/\*.*?\*\/\s*|\/\/[^\n]*\n\s*)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/s', $head) === 1;
    }
}
