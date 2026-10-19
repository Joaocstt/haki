<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Haki;
use Haki\Http\Request;
use Haki\Support\ClassFinder;
use Haki\Support\TypeChecker;
use Haki\Tests\Fixtures\Types\SemStrictController;
use Haki\Tests\Fixtures\Types\SemTipoController;
use Haki\Tests\Fixtures\Types\TipadoController;
use PHPUnit\Framework\TestCase;

final class TypeCheckTest extends TestCase
{
    public function testTypedClassHasNoProblems(): void
    {
        self::assertSame([], (new TypeChecker())->checkClass(TipadoController::class));
    }

    public function testFindsEveryMissingType(): void
    {
        $problems = implode("\n", (new TypeChecker())->checkClass(SemTipoController::class));

        self::assertStringContainsString('SemTipoController::$cache: propriedade sem tipo.', $problems);
        self::assertStringContainsString('SemTipoController::show(): parâmetro $id sem tipo', $problems);
        self::assertStringContainsString('SemTipoController::show(): falta o tipo de retorno', $problems);
        self::assertStringContainsString('(linha 12)', $problems);
    }

    public function testRequiresStrictTypesDeclaration(): void
    {
        $problems = (new TypeChecker())->checkClass(SemStrictController::class);

        self::assertSame(['SemStrictController.php: falta declare(strict_types=1); logo depois do <?php.'], $problems);
    }

    public function testFrameworkAndPhpClassesAreIgnored(): void
    {
        $checker = new TypeChecker();

        self::assertSame([], $checker->checkClass(Haki::class));
        self::assertSame([], $checker->checkClass(\ArrayObject::class));
    }

    private function app(bool $requireTypes): Haki
    {
        $app = new Haki(sys_get_temp_dir(), debug: true);

        if ($requireTypes) {
            $app->requireTypes();
        }

        $app->get('/tipado/{id}', [TipadoController::class, 'show']);
        $app->get('/sem-tipo/{id}', [SemTipoController::class, 'show']);
        $app->get('/closure-sem-tipo/{id}', function ($id) {
            return ['id' => $id];
        });
        $app->get('/closure-tipada/{id}', function (int $id) {
            return ['id' => $id];   // em funções anônimas o retorno é livre
        });

        return $app;
    }

    public function testApiReturnsErrorForUntypedController(): void
    {
        $response = $this->app(true)->handle(Request::create('GET', '/sem-tipo/1'));
        $json = $response->getJson();

        self::assertSame(500, $response->getStatus());
        self::assertStringContainsString('Tipagem obrigatória', $json['detalhes']['mensagem']);
        self::assertStringContainsString('parâmetro $id sem tipo', $json['detalhes']['mensagem']);
        self::assertStringContainsString('requireTypes()', $json['detalhes']['dica']);
    }

    public function testTypedCodeKeepsWorking(): void
    {
        $app = $this->app(true);

        self::assertSame(['id' => 7, 'nome' => 'Nami'], $app->handle(Request::create('GET', '/tipado/7'))->getJson());
        self::assertSame(['id' => 3], $app->handle(Request::create('GET', '/closure-tipada/3'))->getJson());
    }

    public function testUntypedClosureParameterIsAnError(): void
    {
        $json = $this->app(true)->handle(Request::create('GET', '/closure-sem-tipo/1'))->getJson();

        self::assertSame(500, $json['status']);
        self::assertStringContainsString('função anônima', $json['detalhes']['mensagem']);
    }

    public function testDisabledByDefault(): void
    {
        $app = $this->app(false);

        self::assertSame(200, $app->handle(Request::create('GET', '/sem-tipo/1'))->getStatus());
        self::assertSame(200, $app->handle(Request::create('GET', '/closure-sem-tipo/1'))->getStatus());
    }

    public function testProjectClassesAreFoundRecursively(): void
    {
        $classes = ClassFinder::inProject(dirname(__DIR__));

        self::assertContains(SemTipoController::class, $classes);
        self::assertContains(\Haki\Tests\Fixtures\Discover\Contracts\Fiscal\NotaRepositoryInterface::class, $classes);
    }
}
