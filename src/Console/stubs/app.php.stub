<?php

declare(strict_types=1);

use Haki\Haki;

require __DIR__ . '/../vendor/autoload.php';

$app = new Haki(dirname(__DIR__));

// Interfaces de app/Contracts são ligadas sozinhas às classes de app/Repositories pelo nome:
// ProdutoRepositoryInterface → ProdutoRepository ou PdoProdutoRepository.
$app->container()->discover('App\\Contracts', 'App\\Repositories');

// Exige tipos em todo o código do projeto: parâmetro ou retorno sem tipo vira erro com a linha exata.
$app->requireTypes();

// Para casos fora do padrão, ligue manualmente:
// $app->container()->bind(PagamentoInterface::class, StripePagamento::class);

// Middlewares globais, por exemplo:
// $app->middleware(Cors::class);

// Rotas
$app->routes(__DIR__ . '/../routes/api.php');

return $app;
