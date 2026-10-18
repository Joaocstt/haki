<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Haki;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Http\ResponseEmitter;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RequestResponseTest extends TestCase
{
    public function testArrayReturnBecomesJson(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->get('/', fn () => ['mensagem' => 'Olá, ação/teste']);

        $response = $app->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('content-type'));
        self::assertSame('{"mensagem":"Olá, ação/teste"}', $response->getBody());
    }

    public function testStringAndNullReturns(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->get('/texto', fn () => 'oi');
        $app->get('/nada', fn () => null);

        $texto = $app->handle(Request::create('GET', '/texto'));
        self::assertSame('oi', $texto->getBody());
        self::assertStringStartsWith('text/plain', (string) $texto->getHeader('Content-Type'));

        $nada = $app->handle(Request::create('GET', '/nada'));
        self::assertSame(204, $nada->getStatus());
        self::assertSame('', $nada->getBody());
    }

    public function testResponseJsonAliasAndStatus(): void
    {
        $response = (new Response())->responseJson(['ok' => true], 201);

        self::assertSame(201, $response->getStatus());
        self::assertSame(['ok' => true], $response->getJson());
    }

    public function testSerializationFailureBecomes500(): void
    {
        $app = new Haki(sys_get_temp_dir(), debug: false);
        $app->get('/', fn (Response $r) => $r->json(['texto' => "\xB1\x31"]));

        $response = $app->handle(Request::create('GET', '/'));

        self::assertSame(500, $response->getStatus());
        self::assertSame(['erro' => 'Erro interno do servidor', 'status' => 500], $response->getJson());
    }

    public function testReadsJsonBodyWithDotNotation(): void
    {
        $request = Request::create('POST', '/', ['nome' => 'Nami', 'endereco' => ['cidade' => 'Cocoyashi']]);

        self::assertSame('Nami', $request->input('nome'));
        self::assertSame('Cocoyashi', $request->input('endereco.cidade'));
        self::assertNull($request->input('idade'));
        self::assertTrue($request->has('endereco.cidade'));
        self::assertSame(['nome' => 'Nami'], $request->only(['nome']));
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $app = new Haki(sys_get_temp_dir());
        $app->post('/', fn (Request $r) => $r->all());

        $request = new Request('POST', '/', [], ['Content-Type' => 'application/json'], '{quebrado');

        self::assertSame(400, $app->handle($request)->getStatus());
    }

    public function testBearerToken(): void
    {
        self::assertSame('abc.def.ghi', Request::create('GET', '/', [], ['Authorization' => 'Bearer abc.def.ghi'])->bearerToken());
        self::assertNull(Request::create('GET', '/', [], ['Authorization' => 'Basic xyz'])->bearerToken());
        self::assertNull(Request::create('GET', '/')->bearerToken());
    }

    public function testEmitterSendsOnlyOnce(): void
    {
        $emitter = new ResponseEmitter();
        $response = (new Response())->text('uma vez');

        ob_start();
        $emitter->emit($response);
        $output = ob_get_clean();

        self::assertSame('uma vez', $output);

        $this->expectException(LogicException::class);
        $emitter->emit($response);
    }
}
