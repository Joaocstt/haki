<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Console\Commands\CheckCommand;
use Haki\Console\Output;
use PHPUnit\Framework\TestCase;

final class CheckCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-check-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bootstrap', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/bootstrap/app.php');
        @rmdir($this->dir . '/bootstrap');
        @rmdir($this->dir);
    }

    /**
     * @return array{int, string}
     */
    private function check(string $routes): array
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $base = var_export($this->dir, true);

        file_put_contents($this->dir . '/bootstrap/app.php', <<<PHP
            <?php
            require {$autoload};
            \$app = new Haki\Haki({$base});
            \$app->container()->discover('Haki\\\\Tests\\\\Fixtures\\\\Discover\\\\Contracts', 'Haki\\\\Tests\\\\Fixtures\\\\Discover\\\\Repositories');
            {$routes}
            return \$app;
            PHP);

        $stream = fopen('php://memory', 'w+');
        $code = (new CheckCommand($this->dir))->run([], new Output($stream, false));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    }

    public function testReportsEveryInterfaceAndRoute(): void
    {
        [$code, $output] = $this->check(<<<'PHP'
            $app->get('/produtos', [Haki\Tests\Fixtures\Discover\Controllers\ProdutoController::class, 'index']);
            $app->get('/clientes', [Haki\Tests\Fixtures\Discover\Controllers\ClienteController::class, 'index']);
            $app->get('/sumiu', [Haki\Tests\Fixtures\Discover\Controllers\ProdutoController::class, 'naoExiste']);
            $app->get('/', function () { return []; });
            PHP);

        self::assertSame(1, $code);
        self::assertStringContainsString('✔ Haki\Tests\Fixtures\Discover\Contracts\ProdutoRepositoryInterface → Haki\Tests\Fixtures\Discover\Repositories\PdoProdutoRepository', $output);
        self::assertStringContainsString('✔ GET /produtos → Haki\Tests\Fixtures\Discover\Controllers\ProdutoController::index', $output);
        self::assertStringContainsString('✖ GET /clientes', $output);
        self::assertStringContainsString('Você quis dizer Haki\Tests\Fixtures\Discover\Repositories\ClienteRepositry?', $output);
        self::assertStringContainsString('✖ GET /sumiu', $output);
        self::assertStringContainsString('✔ GET / → função anônima', $output);
        self::assertMatchesRegularExpression('/\d+ problemas encontrados\./', $output);
    }

    public function testAllGoodReturnsZero(): void
    {
        [$code, $output] = $this->check(<<<'PHP'
            $app->container()->bind(Haki\Tests\Fixtures\Discover\Contracts\ClienteRepositoryInterface::class, Haki\Tests\Fixtures\Discover\Repositories\ClienteRepositry::class);
            $app->container()->bind(Haki\Tests\Fixtures\Discover\Contracts\PedidoRepositoryInterface::class, Haki\Tests\Fixtures\Discover\Repositories\PedidoRepository::class);
            $app->container()->bind(Haki\Tests\Fixtures\Discover\Contracts\EstoqueRepositoryInterface::class, function () { return new stdClass(); });
            $app->get('/produtos', [Haki\Tests\Fixtures\Discover\Controllers\ProdutoController::class, 'index']);
            PHP);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('ClienteRepositoryInterface → bind no bootstrap', $output);
        self::assertStringContainsString('✔ Tudo certo.', $output);
    }

    public function testMissingBootstrapExplainsWhatToDo(): void
    {
        $stream = fopen('php://memory', 'w+');
        $code = (new CheckCommand(sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(3))))->run([], new Output($stream, false));
        rewind($stream);

        self::assertSame(1, $code);
        self::assertStringContainsString('haki init', (string) stream_get_contents($stream));
    }
}
