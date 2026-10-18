<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Support\Dumper;
use PHPUnit\Framework\TestCase;

enum Cor: string
{
    case Vermelho = 'red';
}

final class No
{
    public ?No $proximo = null;
    protected int $peso = 1;
    private string $segredo = 'x';
}

final class DumperTest extends TestCase
{
    public function testTextShowsEveryTypeDistinctly(): void
    {
        $text = Dumper::toText([[
            'n' => null, 'b' => false, 'i' => 1, 'f' => 2.0, 's' => 'oi',
            'l' => [1], 'v' => [], 'e' => Cor::Vermelho, 'c' => fn (int $x) => $x,
        ]], 'C:/app/teste.php', 12);

        foreach (['teste.php:12', 'array(9) associativo', 'null', 'bool false', 'int 1', 'float 2.0', 'string(2) "oi"',
            'array(1) lista', 'array(0) vazio', "enum Haki\\Tests\\Cor::Vermelho = 'red'", 'Closure(int $x)'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    public function testObjectsShowVisibilityAndRecursion(): void
    {
        $no = new No();
        $no->proximo = $no;

        $text = Dumper::toText([$no]);

        self::assertStringContainsString('+proximo: *recursão* Haki\Tests\No', $text);
        self::assertStringContainsString('#peso: int 1', $text);
        self::assertStringContainsString('-segredo: string(1) "x"', $text);
    }

    public function testHtmlEscapesContentAndIncludesAssetsOnce(): void
    {
        $html = Dumper::toHtml(['<script>alert(1)</script>']);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('<style>', $html);
        self::assertStringNotContainsString('<style>', Dumper::toHtml([1], withAssets: false));
    }

    public function testHelperReturnsTheValue(): void
    {
        ob_start();
        $value = haki(['a' => 1]);
        $many = haki(1, 2);
        ob_end_clean();

        self::assertSame(['a' => 1], $value);
        self::assertSame([1, 2], $many);
    }
}
