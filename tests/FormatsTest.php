<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Validation\Formats;
use Haki\Validation\Rule;
use Haki\Validation\ValidationException;
use Haki\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function casos(): array
    {
        return [
            'cep com máscara' => ['cep', '12345-678', '12345678'],
            'cep sem máscara' => ['cep', '12345678', '12345678'],
            'cep curto' => ['cep', '1234-567', null],

            'cpf com máscara' => ['cpf', '529.982.247-25', '52998224725'],
            'cpf sem máscara' => ['cpf', '52998224725', '52998224725'],
            'cpf dígito errado' => ['cpf', '529.982.247-24', null],
            'cpf repetido' => ['cpf', '111.111.111-11', null],
            'cpf com letras' => ['cpf', '529.982.24A-25', null],

            'cnpj numérico' => ['cnpj', '11.222.333/0001-81', '11222333000181'],
            'cnpj alfanumérico' => ['cnpj', '12.ABC.345/01DE-35', '12ABC34501DE35'],
            'cnpj alfanumérico minúsculo' => ['cnpj', '12abc34501de35', '12ABC34501DE35'],
            'cnpj dígito errado' => ['cnpj', '11.222.333/0001-82', null],
            'cnpj repetido' => ['cnpj', '00.000.000/0000-00', null],

            'documento cpf' => ['cpfOrCnpj', '529.982.247-25', '52998224725'],
            'documento cnpj' => ['cpfOrCnpj', '11.222.333/0001-81', '11222333000181'],
            'documento inválido' => ['cpfOrCnpj', '123', null],

            'celular' => ['phone', '(11) 98765-4321', '11987654321'],
            'celular com +55' => ['phone', '+55 11 98765-4321', '11987654321'],
            'fixo' => ['phone', '(11) 3456-7890', '1134567890'],
            'fixo começando com 1' => ['phone', '(11) 1234-5678', null],
            'telefone sem DDD' => ['phone', '98765-4321', null],
            'DDD com zero' => ['phone', '(01) 98765-4321', null],

            'mobile ok' => ['mobile', '11987654321', '11987654321'],
            'mobile recusa fixo' => ['mobile', '(11) 3456-7890', null],

            'placa mercosul' => ['plate', 'abc-1d23', 'ABC1D23'],
            'placa antiga' => ['plate', 'ABC-1234', 'ABC1234'],
            'placa inválida' => ['plate', 'AB-12345', null],

            'uf minúscula' => ['uf', 'sp', 'SP'],
            'uf inexistente' => ['uf', 'XX', null],

            'hora com zero' => ['time', '9:05', '09:05'],
            'hora máxima' => ['time', '23:59', '23:59'],
            'hora inválida' => ['time', '24:00', null],

            'slug ok' => ['slug', 'meu-produto-2', 'meu-produto-2'],
            'slug com maiúscula' => ['slug', 'Meu-Produto', null],
            'slug com hífen duplo' => ['slug', 'meu--produto', null],

            'cor curta' => ['hexColor', '#F80', '#f80'],
            'cor longa' => ['hexColor', '#ff8800', '#ff8800'],
            'cor sem #' => ['hexColor', 'ff8800', null],
        ];
    }

    #[DataProvider('casos')]
    public function testFormats(string $format, string $input, ?string $expected): void
    {
        self::assertSame($expected, Formats::$format($input));
    }

    public function testRulesUseTheFormatsAndReturnCleanValues(): void
    {
        $rule = new Rule();

        $data = (new Validator())->validate(
            ['cpf' => '529.982.247-25', 'cep' => '12345-678', 'celular' => '(11) 98765-4321', 'uf' => 'rj', 'placa' => 'abc1d23'],
            ['cpf' => $rule->cpf(), 'cep' => $rule->cep(), 'celular' => $rule->mobile(), 'uf' => $rule->uf(), 'placa' => $rule->plate()],
        );

        self::assertSame(['cpf' => '52998224725', 'cep' => '12345678', 'celular' => '11987654321', 'uf' => 'RJ', 'placa' => 'ABC1D23'], $data);
    }

    public function testFormatErrorsExplainTheExpectedValue(): void
    {
        $rule = new Rule();

        try {
            (new Validator())->validate(
                ['cpf' => '111.111.111-11', 'cep' => '123', 'telefone' => '1234', 'documento' => 'x'],
                ['cpf' => $rule->cpf(), 'cep' => $rule->cep(), 'telefone' => $rule->phone(), 'documento' => $rule->cpfOrCnpj()],
                ['cpf' => 'CPF', 'cep' => 'CEP'],
            );
            self::fail('Deveria falhar.');
        } catch (ValidationException $e) {
            self::assertSame([
                'cpf' => ['O campo CPF precisa ser um CPF válido.'],
                'cep' => ['O campo CEP precisa ser um CEP válido (ex.: 12345-678).'],
                'telefone' => ['O campo telefone precisa ser um telefone válido com DDD (ex.: (11) 98765-4321).'],
                'documento' => ['O campo documento precisa ser um CPF ou CNPJ válido.'],
            ], $e->errors());
        }
    }

    public function testFormatsWorkWithOptionalAndCheck(): void
    {
        $rule = new Rule();

        $data = (new Validator())->validate(
            ['cpf' => '529.982.247-25'],
            [
                'cpf' => $rule->cpf()->check(fn (string $cpf): bool => $cpf !== '00000000000', 'CPF bloqueado.'),
                'telefone' => $rule->phone()->optional(),
            ],
        );

        self::assertSame(['cpf' => '52998224725', 'telefone' => null], $data);
    }
}
