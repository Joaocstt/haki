<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Exceptions\HakiException;
use Haki\Haki;
use Haki\Http\Request;
use PHPUnit\Framework\TestCase;

final class RouteFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'haki-rotas-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/routes', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/routes/*.php') ?: []);
        rmdir($this->dir . '/routes');
        rmdir($this->dir);
    }

    private function write(string $name, string $code): void
    {
        file_put_contents($this->dir . '/routes/' . $name, "<?php\n" . $code);
    }

    public function testLooseRouteFileSeesTheAppVariable(): void
    {
        $this->write('api.php', '/** @var Haki\Haki $app */ $app->get("/", function () { return ["formato" => "solto"]; });');

        $app = new Haki($this->dir);
        $app->routes('routes/api.php'); // relativo ao basePath

        self::assertSame(['formato' => 'solto'], $app->handle(Request::create('GET', '/'))->getJson());
    }

    public function testClosureRouteFileStillWorks(): void
    {
        $this->write('api.php', 'return function (Haki\Haki $app): void { $app->get("/", function () { return ["formato" => "closure"]; }); };');

        $app = new Haki($this->dir);
        $app->routes($this->dir . '/routes/api.php'); // absoluto

        self::assertSame(['formato' => 'closure'], $app->handle(Request::create('GET', '/'))->getJson());
    }

    public function testSeveralRouteFilesCanBeLoaded(): void
    {
        $this->write('api.php', '$app->get("/a", function () { return "a"; });');
        $this->write('admin.php', '$app->get("/b", function () { return "b"; });');

        $app = (new Haki($this->dir))->routes('routes/api.php')->routes('routes/admin.php');

        self::assertSame('a', $app->handle(Request::create('GET', '/a'))->getBody());
        self::assertSame('b', $app->handle(Request::create('GET', '/b'))->getBody());
    }

    public function testMissingFileExplainsTheProblem(): void
    {
        $this->expectException(HakiException::class);
        $this->expectExceptionMessage('Arquivo de rotas não encontrado');

        (new Haki($this->dir))->routes('routes/nao-existe.php');
    }
}
