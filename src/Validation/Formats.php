<?php

declare(strict_types=1);

namespace Haki\Validation;

/**
 * Formatos brasileiros prontos. Cada método aceita o valor com ou sem máscara e devolve
 * a versão limpa (normalizada), ou null se for inválido.
 *
 *     Formats::cpf('529.982.247-25')   // '52998224725'
 *     Formats::cpf('111.111.111-11')   // null
 *
 * Também podem ser usados fora da validação.
 */
final class Formats
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** CEP: 8 dígitos. "12345-678" → "12345678". */
    public static function cep(string $value): ?string
    {
        $digits = self::digits($value, '.- ');

        return $digits !== null && strlen($digits) === 8 ? $digits : null;
    }

    /** CPF com dígitos verificadores. "529.982.247-25" → "52998224725". */
    public static function cpf(string $value): ?string
    {
        $digits = self::digits($value, '.- ');

        if ($digits === null || strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return null;
        }

        foreach ([9, 10] as $position) {
            $sum = 0;

            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digits[$i] * ($position + 1 - $i);
            }

            $check = ($sum * 10) % 11 % 10;

            if ($check !== (int) $digits[$position]) {
                return null;
            }
        }

        return $digits;
    }

    /**
     * CNPJ com dígitos verificadores, numérico ou alfanumérico (formato da Receita a partir de 2026).
     * "11.222.333/0001-81" → "11222333000181".
     */
    public static function cnpj(string $value): ?string
    {
        $clean = strtoupper(str_replace(['.', '/', '-', ' '], '', trim($value)));

        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $clean) !== 1 || preg_match('/^(.)\1{13}$/', $clean) === 1) {
            return null;
        }

        foreach ([12, 13] as $length) {
            $weights = $length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (ord($clean[$i]) - 48) * $weights[$i];
            }

            $remainder = $sum % 11;
            $check = $remainder < 2 ? 0 : 11 - $remainder;

            if ($check !== (int) $clean[$length]) {
                return null;
            }
        }

        return $clean;
    }

    /** CPF ou CNPJ, conforme a quantidade de caracteres. */
    public static function cpfOrCnpj(string $value): ?string
    {
        return self::cpf($value) ?? self::cnpj($value);
    }

    /**
     * Telefone fixo ou celular com DDD, com ou sem +55.
     * "(11) 98765-4321" → "11987654321"; "(11) 3456-7890" → "1134567890".
     */
    public static function phone(string $value): ?string
    {
        $digits = self::digits(ltrim(trim($value), '+'), '()- .');

        if ($digits === null) {
            return null;
        }

        // Remove o código do país (55) quando ele vem junto.
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        $fixo = preg_match('/^[1-9]{2}[2-5]\d{7}$/', $digits) === 1;
        $celular = preg_match('/^[1-9]{2}9\d{8}$/', $digits) === 1;

        return $fixo || $celular ? $digits : null;
    }

    /** Só celular (DDD + 9 + 8 dígitos). "(11) 98765-4321" → "11987654321". */
    public static function mobile(string $value): ?string
    {
        $phone = self::phone($value);

        return $phone !== null && strlen($phone) === 11 ? $phone : null;
    }

    /** Placa de veículo, padrão antigo (ABC-1234) ou Mercosul (ABC1D23). Devolve "ABC1D23". */
    public static function plate(string $value): ?string
    {
        $clean = strtoupper(str_replace(['-', ' '], '', trim($value)));

        return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $clean) === 1 ? $clean : null;
    }

    /** Sigla de estado. "sp" → "SP". */
    public static function uf(string $value): ?string
    {
        $uf = strtoupper(trim($value));

        return in_array($uf, self::UFS, true) ? $uf : null;
    }

    /** Horário HH:MM (00:00 a 23:59). "9:05" → "09:05". */
    public static function time(string $value): ?string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) !== 1) {
            return null;
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }

    /** Slug de URL: letras minúsculas, números e hífens. "meu-produto-2". */
    public static function slug(string $value): ?string
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 ? $value : null;
    }

    /** Cor hexadecimal. "#FFF" ou "#ff8800" → "#ff8800" (minúsculas). */
    public static function hexColor(string $value): ?string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', trim($value)) === 1 ? strtolower(trim($value)) : null;
    }

    /**
     * Remove os caracteres de máscara permitidos e confere se sobrou só dígito.
     */
    private static function digits(string $value, string $mask): ?string
    {
        $clean = str_replace(str_split($mask), '', trim($value));

        return $clean !== '' && ctype_digit($clean) ? $clean : null;
    }
}
