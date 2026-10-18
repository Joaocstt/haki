<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Auth\Storage\ArrayTokenStore;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Tests\Support\TestKeys;
use Haki\Validation\FormRequest;
use Haki\Validation\Rule;
use PHPUnit\Framework\TestCase;

final class EmailsCadastrados
{
    /** @var list<string> */
    public array $emails = ['zoro@exemplo.com'];
}

final class CriarClienteRequest extends FormRequest
{
    public function __construct(private readonly EmailsCadastrados $cadastrados)
    {
    }

    public function rules(Rule $rule): array
    {
        return [
            'nome' => $rule->text()->length(3, 100),
            'email' => $rule->email(),
            'cpf' => $rule->text()->length(11, 11),
            'idade' => $rule->integer()->min(18)->optional(),
            'endereco' => ['cidade' => $rule->text()],
        ];
    }

    public function labels(): array
    {
        return ['email' => 'e-mail', 'cpf' => 'CPF'];
    }

    protected function prepare(array $data): array
    {
        $data['cpf'] = preg_replace('/\D/', '', (string) ($data['cpf'] ?? ''));

        return $data;
    }

    protected function check(array $data): void
    {
        if (in_array($data['email'], $this->cadastrados->emails, true)) {
            $this->fail('email', 'Este e-mail já está cadastrado.');
        }
    }
}

final class BuscaRequest extends FormRequest
{
    public function rules(Rule $rule): array
    {
        return ['busca' => $rule->text()->length(2), 'pagina' => $rule->integer()->min(1)->optional(1)];
    }
}

final class FormRequestTest extends TestCase
{
    private Haki $app;

    protected function setUp(): void
    {
        $this->app = new Haki(sys_get_temp_dir(), debug: false);

        $this->app->post('/clientes', function (CriarClienteRequest $request) {
            return [
                'nome' => $request->nome,
                'idade' => $request->idade,
                'cidade' => $request->get('endereco.cidade'),
                'cpf' => $request->cpf,
                'todos' => array_keys($request->validated()),
            ];
        });

        $this->app->get('/busca', function (BuscaRequest $request) {
            return $request->validated();
        });
    }

    public function testValidRequestReachesTheControllerWithConvertedData(): void
    {
        $response = $this->app->handle(Request::create('POST', '/clientes', [
            'nome' => 'Nami',
            'email' => 'nami@exemplo.com',
            'cpf' => '123.456.789-01',
            'idade' => '20',
            'endereco' => ['cidade' => 'Cocoyashi'],
            'perfil' => 'admin',
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame([
            'nome' => 'Nami',
            'idade' => 20,
            'cidade' => 'Cocoyashi',
            'cpf' => '12345678901',
            'todos' => ['nome', 'email', 'cpf', 'idade', 'endereco'],
        ], $response->getJson());
    }

    public function testInvalidRequestReturns422WithFieldErrors(): void
    {
        $response = $this->app->handle(Request::create('POST', '/clientes', ['nome' => 'Lu', 'email' => 'x', 'cpf' => '123']));

        self::assertSame(422, $response->getStatus());
        self::assertSame([
            'erro' => 'Dados inválidos',
            'status' => 422,
            'campos' => [
                'nome' => ['O campo nome precisa ter entre 3 e 100 caracteres.'],
                'email' => ['O campo e-mail precisa ser um e-mail válido.'],
                'cpf' => ['O campo CPF precisa ter exatamente 11 caracteres.'],
                'endereco' => ['O campo endereco é obrigatório.'],
            ],
        ], $response->getJson());
    }

    public function testCheckRunsAfterRulesWithDependencies(): void
    {
        $response = $this->app->handle(Request::create('POST', '/clientes', [
            'nome' => 'Zoro', 'email' => 'zoro@exemplo.com', 'cpf' => '12345678901', 'endereco' => ['cidade' => 'Shimotsuki'],
        ]));

        self::assertSame(422, $response->getStatus());
        self::assertSame(['email' => ['Este e-mail já está cadastrado.']], $response->getJson()['campos']);
    }

    public function testQueryStringIsValidatedToo(): void
    {
        self::assertSame(['busca' => 'espada', 'pagina' => 1], $this->app->handle(Request::create('GET', '/busca?busca=espada'))->getJson());
        self::assertSame(422, $this->app->handle(Request::create('GET', '/busca?busca=e'))->getStatus());
    }

    public function testInlineValidateWithClosure(): void
    {
        $this->app->post('/login', function (Request $request) {
            return $request->validate(function (Rule $rule) {
                return ['email' => $rule->email(), 'senha' => $rule->text()->length(8)];
            });
        });

        $ok = $this->app->handle(Request::create('POST', '/login', ['email' => 'a@b.com', 'senha' => '12345678']));
        $erro = $this->app->handle(Request::create('POST', '/login', ['email' => 'a@b.com']));

        self::assertSame(['email' => 'a@b.com', 'senha' => '12345678'], $ok->getJson());
        self::assertSame(['senha' => ['O campo senha é obrigatório.']], $erro->getJson()['campos']);
    }

    public function testAuthenticationRunsBeforeValidation(): void
    {
        $this->app->auth(['secret' => TestKeys::SECRET, 'storage' => new ArrayTokenStore()]);
        $this->app->post('/protegida', function (BuscaRequest $request) {
            return [];
        })->auth();

        self::assertSame(401, $this->app->handle(Request::create('POST', '/protegida'))->getStatus());
    }

    public function testUndeclaredFieldAccessExplainsTheProblem(): void
    {
        $this->app->get('/erro', function (BuscaRequest $request) {
            return [$request->naoExiste];
        });

        $app = new Haki(sys_get_temp_dir(), debug: true);
        $app->get('/erro', function (BuscaRequest $request) {
            return [$request->naoExiste];
        });

        $json = $app->handle(Request::create('GET', '/erro?busca=ab'))->getJson();

        self::assertStringContainsString('Declare-o em rules()', $json['detalhes']['mensagem']);
    }
}
