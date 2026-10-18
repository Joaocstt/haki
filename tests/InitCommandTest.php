<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Console\Commands\InitCommand;
use Haki\Console\Output;
use Haki\Haki;
use Haki\Http\Request;
use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-init-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/composer.json', json_encode(['require' => ['shankscode/haki' => '^1.0']]));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->dir);
    }

    private function run_(array $args = []): string
    {
        $stream = fopen('php://memory', 'w+');
        $command = new InitCommand($this->dir, function (): bool {
            return true;
        });

        self::assertSame(0, $command->run($args, new Output($stream, false)));

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testCreatesTheDefaultStructure(): void
    {
        $this->run_();

        foreach (['app/Controllers/HomeController.php', 'bootstrap/app.php', 'routes/api.php', 'public/index.php',
            '.env', '.env.example', '.gitignore', 'app/Services/.gitkeep', 'storage/.gitkeep'] as $file) {
            self::assertFileExists($this->dir . '/' . $file);
        }

        $composer = json_decode((string) file_get_contents($this->dir . '/composer.json'), true);
        self::assertSame('app/', $composer['autoload']['psr-4']['App\\']);
        self::assertSame('^1.0', $composer['require']['shankscode/haki'], 'O resto do composer.json é preservado.');
    }

    public function testExampleRouteUsesOnlyOneControllerAndNoArrowFunctions(): void
    {
        $this->run_();

        $routes = (string) file_get_contents($this->dir . '/routes/api.php');

        self::assertSame(1, substr_count($routes, '$app->get('));
        self::assertStringContainsString('[HomeController::class, \'index\']', $routes);
        self::assertStringNotContainsString('fn (', $routes);
        self::assertCount(1, glob($this->dir . '/app/Controllers/*.php'));
    }

    public function testDoesNotOverwriteExistingFilesWithoutForce(): void
    {
        file_put_contents($this->dir . '/.env', 'MEU=valor');

        $output = $this->run_();
        self::assertSame('MEU=valor', file_get_contents($this->dir . '/.env'));
        self::assertStringContainsString('mantido  .env', $output);

        $this->run_(['--force']);
        self::assertStringContainsString('APP_ENV=development', (string) file_get_contents($this->dir . '/.env'));
    }

    public function testGeneratedProjectAnswersTheExampleRoute(): void
    {
        $this->run_();

        require_once $this->dir . '/app/Controllers/HomeController.php';

        $app = new Haki($this->dir);
        (require $this->dir . '/routes/api.php')($app);

        $response = $app->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->getStatus());
        self::assertSame(['mensagem' => 'Bem-vindo ao Haki!', 'caminho' => '/'], $response->getJson());
    }
}
