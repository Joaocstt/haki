<?php

declare(strict_types=1);

namespace Haki\Validation;

use BackedEnum;
use InvalidArgumentException;

/**
 * Cria as regras de cada campo. Chega como $rule em rules() e em $request->validate():
 *
 *     public function rules(Rule $rule): array
 *     {
 *         return [
 *             'nome'     => $rule->text()->length(3, 100),
 *             'email'    => $rule->email(),
 *             'idade'    => $rule->integer()->min(18)->optional(),
 *             'endereco' => ['cidade' => $rule->text()],
 *             'tags'     => $rule->list($rule->oneOf('vip', 'novo')),
 *         ];
 *     }
 */
final class Rule
{
    /** Texto. Espaços nas pontas são removidos. */
    public function text(): Field
    {
        return new Field('text');
    }

    /** Número inteiro. "20" vira 20. */
    public function integer(): Field
    {
        return new Field('integer');
    }

    /** Número inteiro ou decimal. "9.9" vira 9.9. */
    public function number(): Field
    {
        return new Field('number');
    }

    /** Verdadeiro ou falso. Aceita true/false, 1/0, "sim"/"não", "on"/"off". */
    public function boolean(): Field
    {
        return new Field('boolean');
    }

    public function email(): Field
    {
        return new Field('email');
    }

    /** URL começando com http:// ou https://. */
    public function url(): Field
    {
        return new Field('url');
    }

    public function uuid(): Field
    {
        return new Field('uuid');
    }

    // ------------------------------------------------------------------ formatos prontos
    // Aceitam o valor com ou sem máscara e entregam a versão limpa.

    /** CEP. "12345-678" → "12345678". */
    public function cep(): Field
    {
        return new Field('cep');
    }

    /** CPF, conferindo os dígitos verificadores. "529.982.247-25" → "52998224725". */
    public function cpf(): Field
    {
        return new Field('cpf');
    }

    /** CNPJ numérico ou alfanumérico, conferindo os dígitos verificadores. → "11222333000181". */
    public function cnpj(): Field
    {
        return new Field('cnpj');
    }

    /** CPF ou CNPJ (útil para "documento" de pessoa física ou jurídica). */
    public function cpfOrCnpj(): Field
    {
        return new Field('cpfOrCnpj');
    }

    /** Telefone fixo ou celular com DDD, com ou sem +55. "(11) 98765-4321" → "11987654321". */
    public function phone(): Field
    {
        return new Field('phone');
    }

    /** Só celular com DDD. "(11) 98765-4321" → "11987654321". */
    public function mobile(): Field
    {
        return new Field('mobile');
    }

    /** Placa de veículo, antiga ou Mercosul. "abc-1d23" → "ABC1D23". */
    public function plate(): Field
    {
        return new Field('plate');
    }

    /** Sigla de estado. "sp" → "SP". */
    public function uf(): Field
    {
        return new Field('uf');
    }

    /** Horário HH:MM. "9:05" → "09:05". */
    public function time(): Field
    {
        return new Field('time');
    }

    /** Slug de URL: "meu-produto-2". */
    public function slug(): Field
    {
        return new Field('slug');
    }

    /** Cor hexadecimal: "#ff8800" ou "#f80". */
    public function hexColor(): Field
    {
        return new Field('hexColor');
    }

    // ------------------------------------------------------------------ datas, listas e objetos

    /**
     * Data no formato informado. Vira um DateTimeImmutable.
     *
     *     $rule->date()                 2026-12-31
     *     $rule->date('d/m/Y')          31/12/2026
     *     $rule->date('Y-m-d H:i')      2026-12-31 18:30
     */
    public function date(string $format = 'Y-m-d'): Field
    {
        return new Field('date', ['format' => $format]);
    }

    /**
     * Um destes valores.
     *
     *     $rule->oneOf('gratis', 'pro')
     */
    public function oneOf(string|int ...$values): Field
    {
        return (new Field('any'))->oneOf(...$values);
    }

    /**
     * Um dos valores de um enum. Vira o próprio enum.
     *
     *     $rule->enum(Plano::class)
     *
     * @param class-string<BackedEnum> $class
     */
    public function enum(string $class): Field
    {
        if (!is_subclass_of($class, BackedEnum::class)) {
            throw new InvalidArgumentException("{$class} precisa ser um enum com valores (enum Plano: string { ... }).");
        }

        return new Field('enum', ['class' => $class]);
    }

    /**
     * Lista em que cada item segue a regra informada.
     *
     *     $rule->list($rule->text())
     *     $rule->list(['nome' => $rule->text(), 'qtd' => $rule->integer()])   lista de objetos
     *
     * @param Field|array<string, Field|array<mixed>> $item
     */
    public function list(Field|array $item): Field
    {
        return new Field('list', ['item' => $item]);
    }

    /**
     * Objeto com campos próprios. Um array simples faz o mesmo; use object() quando
     * precisar de ->optional():
     *
     *     'endereco' => $rule->object(['cidade' => $rule->text()])->optional()
     *
     * @param array<string, Field|array<mixed>> $fields
     */
    public function object(array $fields): Field
    {
        return new Field('object', ['fields' => $fields]);
    }
}
