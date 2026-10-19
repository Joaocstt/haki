<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Container\Container;
use Haki\Exceptions\ContainerException;
use Haki\Tests\Fixtures\Discover\Contracts\ClienteRepositoryInterface;
use Haki\Tests\Fixtures\Discover\Contracts\EstoqueRepositoryInterface;
use Haki\Tests\Fixtures\Discover\Contracts\Fiscal\NotaRepositoryInterface;
use Haki\Tests\Fixtures\Discover\Contracts\IPagamento;
use Haki\Tests\Fixtures\Discover\Contracts\PedidoRepositoryInterface;
use Haki\Tests\Fixtures\Discover\Contracts\ProdutoRepositoryInterface;
use Haki\Tests\Fixtures\Discover\Controllers\ProdutoController;
use Haki\Tests\Fixtures\Discover\Gateways\StripePagamento;
use Haki\Tests\Fixtures\Discover\Repositories\Fiscal\NotaRepository;
use Haki\Tests\Fixtures\Discover\Repositories\PdoPedidoRepository;
use Haki\Tests\Fixtures\Discover\Repositories\PdoProdutoRepository;
use PHPUnit\Framework\TestCase;

final class DiscoverTest extends TestCase
{
    private const NS = 'Haki\\Tests\\Fixtures\\Discover';

    private function container(): Container
    {
        return (new Container())->discover(self::NS . '\\Contracts', self::NS . '\\Repositories');
    }

    private function assertContainerError(callable $action, string ...$fragments): void
    {
        try {
            $action();
            self::fail('Era esperado um erro do container.');
        } catch (ContainerException $e) {
            $text = $e->getMessage() . ' ' . $e->getHint();

            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $text);
            }
        }
    }

    public function testFindsTheImplementationByNameAndInjectsIt(): void
    {
        $container = $this->container();

        self::assertInstanceOf(PdoProdutoRepository::class, $container->get(ProdutoRepositoryInterface::class));
        self::assertInstanceOf(PdoProdutoRepository::class, $container->get(ProdutoController::class)->produtos);
    }

    public function testExplicitBindWins(): void
    {
        $container = $this->container()->bind(PedidoRepositoryInterface::class, PdoPedidoRepository::class);

        self::assertInstanceOf(PdoPedidoRepository::class, $container->get(PedidoRepositoryInterface::class));
    }

    public function testAmbiguityIsAnErrorNeverAGuess(): void
    {
        $this->assertContainerError(
            fn () => $this->container()->get(PedidoRepositoryInterface::class),
            'Mais de uma classe serve',
            'PedidoRepository',
            'PdoPedidoRepository',
            'bind(PedidoRepositoryInterface::class',
        );
    }

    public function testTypoInImplementationNameSuggestsTheClosestClass(): void
    {
        $this->assertContainerError(
            fn () => $this->container()->get(ClienteRepositoryInterface::class),
            'Nenhuma implementação encontrada',
            self::NS . '\\Repositories\\ClienteRepository',
            self::NS . '\\Repositories\\PdoClienteRepository',
            'Você quis dizer ' . self::NS . '\\Repositories\\ClienteRepositry?',
        );
    }

    public function testClassThatDoesNotImplementTheInterfaceIsRejected(): void
    {
        $this->assertContainerError(
            fn () => $this->container()->get(EstoqueRepositoryInterface::class),
            'não implementa essa interface',
            'implements EstoqueRepositoryInterface',
        );
    }

    public function testCustomPrefixSuffixAndPatterns(): void
    {
        $container = (new Container())->discover(
            from: self::NS . '\\Contracts',
            to: self::NS . '\\Gateways',
            suffix: '',
            prefix: 'I',
            patterns: ['Stripe{name}'],
        );

        self::assertInstanceOf(StripePagamento::class, $container->get(IPagamento::class));
    }

    public function testSubNamespacesAreMirrored(): void
    {
        self::assertInstanceOf(NotaRepository::class, $this->container()->get(NotaRepositoryInterface::class));
    }

    public function testTypoInClassNameSuggestsTheClosestClass(): void
    {
        $this->assertContainerError(
            fn () => (new Container())->get(self::NS . '\\Contracts\\ProdutoRepositoryInterfce'),
            'a classe não existe',
            'Você quis dizer ' . self::NS . '\\Contracts\\ProdutoRepositoryInterface?',
        );
    }

    public function testWithoutDiscoverTheErrorMentionsIt(): void
    {
        $this->assertContainerError(
            fn () => (new Container())->get(ProdutoRepositoryInterface::class),
            'é uma interface',
            'discover(',
        );
    }
}
