<?php

declare(strict_types=1);

use App\Contracts\UsuarioRepositoryInterface;
use App\Middleware\Cors;
use App\Repositories\ArrayUsuarioRepository;
use Haki\Haki;

// Num projeto real: require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

$app = new Haki(dirname(__DIR__));

// 1. LigaÃ§Ãµes do container: qual implementaÃ§Ã£o usar para cada interface.
$app->container()->singleton(UsuarioRepositoryInterface::class, ArrayUsuarioRepository::class);

// 2. Middlewares globais.
$app->middleware(Cors::class);

// 3. Rotas.
$app->routes(__DIR__ . '/../routes/api.php');

return $app;
