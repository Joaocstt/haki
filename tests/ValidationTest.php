<?php

declare(strict_types=1);

namespace Haki\Tests;

use DateTimeImmutable;
use Haki\Validation\Field;
use Haki\Validation\Rule;
use Haki\Validation\ValidationException;
use Haki\Validation\Validator;
use PHPUnit\Framework\TestCase;

enum Plano: string
{
    case Gratis = 'gratis';
    case Pro = 'pro';
}

final class ValidationTest extends TestCase
{
    private Rule $rule;

    protected function setUp(): void
    {
        $this->rule = new Rule();
    }

    /**
     * @param array<string, mixed>              $data
     * @param array<string, Field|array<mixed>> $rules
     * @return array<string, mixed>
     */
    private function valid(array $data, array $rules, array $labels = []): array
    {
        return (new Validator())->validate($data, $rules, $labels);
    }

    /**
     * @param array<string, mixed>              $data
     * @param array<string, Field|array<mixed>> $rules
     * @return array<string, list<string>>
     */
    private function errors(array $data, array $rules, array $labels = []): array
    {
        try {
            (new Validator())->validate($data, $rules, $labels);
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatus());

            return $e->errors();
        }

        self::fail('Era esperado um erro de validação.');
    }

    public function testFullExampleIsValidatedAndConverted(): void
    {
        $r = $this->rule;

        $data = $this->valid(
            [
                'nome' => '  Nami  ',
                'email' => 'nami@exemplo.com',
                'idade' => '20',
                'endereco' => ['cidade' => 'Cocoyashi', 'cep' => '12345-678'],
                'tags' => ['vip'],
                'perfil' => 'admin', // não declarado: deve sumir
            ],
            [
                'nome' => $r->text()->length(3, 100),
                'email' => $r->email(),
                'idade' => $r->integer()->min(18)->optional(),
                'endereco' => [
                    'cidade' => $r->text(),
                    'cep' => $r->text()->pattern('/^\d{5}-?\d{3}$/'),
                ],
                'tags' => $r->list($r->oneOf('vip', 'novo')),
            ],
        );

        self::assertSame([
            'nome' => 'Nami',
            'email' => 'nami@exemplo.com',
            'idade' => 20,
            'endereco' => ['cidade' => 'Cocoyashi', 'cep' => '12345-678'],
            'tags' => ['vip'],
        ], $data);
    }

    public function testFieldsAreRequiredByDefault(): void
    {
        $r = $this->rule;

        $errors = $this->errors(['nome' => '   '], ['nome' => $r->text(), 'email' => $r->email()]);

        self::assertSame(['O campo nome é obrigatório.'], $errors['nome']);
        self::assertSame(['O campo email é obrigatório.'], $errors['email']);
    }

    public function testOptionalFieldsReceiveDefault(): void
    {
        $r = $this->rule;

        $data = $this->valid([], [
            'idade' => $r->integer()->optional(),
            'pagina' => $r->integer()->optional(1),
            'ativo' => $r->boolean()->optional(true),
        ]);

        self::assertSame(['idade' => null, 'pagina' => 1, 'ativo' => true], $data);
    }

    public function testTypeConversions(): void
    {
        $r = $this->rule;

        $data = $this->valid(
            ['i' => '42', 'n' => '9.9', 'b1' => 'sim', 'b2' => '0', 'd' => '2026-12-31', 'br' => '31/12/2026', 'p' => 'pro', 'u' => 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11'],
            [
                'i' => $r->integer(),
                'n' => $r->number(),
                'b1' => $r->boolean(),
                'b2' => $r->boolean(),
                'd' => $r->date(),
                'br' => $r->date('d/m/Y'),
                'p' => $r->enum(Plano::class),
                'u' => $r->uuid(),
            ],
        );

        self::assertSame(42, $data['i']);
        self::assertSame(9.9, $data['n']);
        self::assertTrue($data['b1']);
        self::assertFalse($data['b2']);
        self::assertInstanceOf(DateTimeImmutable::class, $data['d']);
        self::assertSame('2026-12-31', $data['br']->format('Y-m-d'));
        self::assertSame(Plano::Pro, $data['p']);
        self::assertSame('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $data['u']);
    }

    public function testTypeErrorsHaveClearMessages(): void
    {
        $r = $this->rule;

        $errors = $this->errors(
            ['i' => '4.5', 'n' => 'abc', 'b' => 'talvez', 'e' => 'nao-e-email', 'url' => 'ftp://x', 'd' => '2026-02-30', 'p' => 'ouro', 't' => ['x']],
            [
                'i' => $r->integer(),
                'n' => $r->number(),
                'b' => $r->boolean(),
                'e' => $r->email(),
                'url' => $r->url(),
                'd' => $r->date(),
                'p' => $r->enum(Plano::class),
                't' => $r->text(),
            ],
        );

        self::assertSame(['O campo i precisa ser um número inteiro.'], $errors['i']);
        self::assertSame(['O campo n precisa ser um número.'], $errors['n']);
        self::assertSame(['O campo b precisa ser verdadeiro ou falso.'], $errors['b']);
        self::assertSame(['O campo e precisa ser um e-mail válido.'], $errors['e']);
        self::assertStringContainsString('URL válida', $errors['url'][0]);
        self::assertSame(['O campo d precisa ser uma data válida (ex.: 2026-12-31).'], $errors['d']);
        self::assertSame(['O campo p precisa ser um destes: gratis, pro.'], $errors['p']);
        self::assertSame(['O campo t precisa ser um texto.'], $errors['t']);
    }

    public function testLengthMinMaxAndBetween(): void
    {
        $r = $this->rule;

        $errors = $this->errors(
            ['a' => 'Lu', 'b' => 'muito longo', 'c' => 'abc', 'd' => 'ab', 'idade' => 15, 'nota' => 11, 'faixa' => 0],
            [
                'a' => $r->text()->length(3, 100),
                'b' => $r->text()->length(max: 5),
                'c' => $r->text()->length(4),
                'd' => $r->text()->length(3, 3),
                'idade' => $r->integer()->min(18),
                'nota' => $r->number()->max(10),
                'faixa' => $r->integer()->between(1, 5),
            ],
        );

        self::assertSame(['O campo a precisa ter entre 3 e 100 caracteres.'], $errors['a']);
        self::assertSame(['O campo b pode ter no máximo 5 caracteres.'], $errors['b']);
        self::assertSame(['O campo c precisa ter pelo menos 4 caracteres.'], $errors['c']);
        self::assertSame(['O campo d precisa ter exatamente 3 caracteres.'], $errors['d']);
        self::assertSame(['O campo idade precisa ser no mínimo 18.'], $errors['idade']);
        self::assertSame(['O campo nota pode ser no máximo 10.'], $errors['nota']);
        self::assertSame(['O campo faixa precisa ser no mínimo 1.'], $errors['faixa']);
    }

    public function testLabelsNestedPathsAndListItems(): void
    {
        $r = $this->rule;

        $errors = $this->errors(
            ['email' => 'x', 'endereco' => ['cep' => '123'], 'tags' => ['vip', 'ouro']],
            [
                'email' => $r->email(),
                'endereco' => ['cidade' => $r->text(), 'cep' => $r->text()->pattern('/^\d{5}-?\d{3}$/', 'Informe um CEP como 12345-678.')],
                'tags' => $r->list($r->oneOf('vip', 'novo')),
            ],
            ['email' => 'e-mail', 'endereco.cidade' => 'cidade'],
        );

        self::assertSame(['O campo e-mail precisa ser um e-mail válido.'], $errors['email']);
        self::assertSame(['O campo cidade é obrigatório.'], $errors['endereco.cidade']);
        self::assertSame(['Informe um CEP como 12345-678.'], $errors['endereco.cep']);
        self::assertSame(['O item 2 de tags precisa ser um destes: vip, novo.'], $errors['tags.1']);
    }

    public function testListsOfObjectsAndListLength(): void
    {
        $r = $this->rule;

        $data = $this->valid(
            ['itens' => [['produto' => 1, 'qtd' => '2'], ['produto' => '7', 'qtd' => 1]]],
            ['itens' => $r->list(['produto' => $r->integer(), 'qtd' => $r->integer()->min(1)])->length(1, 10)],
        );
        self::assertSame([['produto' => 1, 'qtd' => 2], ['produto' => 7, 'qtd' => 1]], $data['itens']);

        $errors = $this->errors(['itens' => []], ['itens' => $r->list($r->integer())->length(1)]);
        self::assertSame(['O campo itens precisa ter pelo menos 1 item.'], $errors['itens']);

        $errors = $this->errors(['itens' => 'x'], ['itens' => $r->list($r->integer())]);
        self::assertSame(['O campo itens precisa ser uma lista.'], $errors['itens']);
    }

    public function testObjectsCanBeOptional(): void
    {
        $r = $this->rule;

        $data = $this->valid([], ['endereco' => $r->object(['cidade' => $r->text()])->optional()]);
        self::assertSame(['endereco' => null], $data);

        $errors = $this->errors(['endereco' => 'texto'], ['endereco' => ['cidade' => $r->text()]]);
        self::assertSame(['O campo endereco precisa ser um objeto.'], $errors['endereco']);
    }

    public function testSameCheckAndCustomMessage(): void
    {
        $r = $this->rule;

        $errors = $this->errors(
            ['senha' => 'segredo123', 'senha_confirmacao' => 'outra', 'cpf' => '111', 'apelido' => 'x'],
            [
                'senha' => $r->text()->length(8),
                'senha_confirmacao' => $r->text()->same('senha'),
                'cpf' => $r->text()->check(fn (string $cpf): bool => strlen($cpf) === 11, 'Informe um CPF válido.'),
                'apelido' => $r->text()->length(3)->message('Escolha um apelido com 3 letras ou mais.'),
            ],
        );

        self::assertArrayNotHasKey('senha', $errors);
        self::assertSame(['O campo senha confirmacao precisa ser igual ao campo senha.'], $errors['senha_confirmacao']);
        self::assertSame(['Informe um CPF válido.'], $errors['cpf']);
        self::assertSame(['Escolha um apelido com 3 letras ou mais.'], $errors['apelido']);
    }

    public function testOneOfReturnsTheDeclaredValue(): void
    {
        $r = $this->rule;

        self::assertSame(['nivel' => 2], $this->valid(['nivel' => '2'], ['nivel' => $r->oneOf(1, 2, 3)]));
    }
}
