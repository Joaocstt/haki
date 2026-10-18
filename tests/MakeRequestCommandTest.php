<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Console\Commands\MakeRequestCommand;
use Haki\Console\Output;
use PHPUnit\Framework\TestCase;

final class MakeRequestCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-make-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/app/Requests/*.php') ?: []);
        @rmdir($this->dir . '/app/Requests');
        @rmdir($this->dir . '/app');
        rmdir($this->dir);
    }

    private function run_(array $args): int
    {
        return (new MakeRequestCommand($this->dir))->run($args, new Output(fopen('php://memory', 'w+'), false));
    }

    public function testCreatesAValidFormRequestClass(): void
    {
        self::assertSame(0, $this->run_(['CriarClienteRequest']));

        $file = $this->dir . '/app/Requests/CriarClienteRequest.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString('final class CriarClienteRequest extends FormRequest', $code);
        self::assertStringContainsString('public function rules(Rule $rule): array', $code);
        self::assertStringContainsString('$rule->text()', $code);
        self::assertStringNotContainsString('{{class}}', $code);
    }

    public function testRejectsInvalidNamesAndExistingFiles(): void
    {
        self::assertSame(1, $this->run_([]));
        self::assertSame(1, $this->run_(['criar-cliente']));

        $this->run_(['Teste']);
        self::assertSame(1, $this->run_(['Teste']));
        self::assertSame(0, $this->run_(['Teste', '--force']));
    }
}
