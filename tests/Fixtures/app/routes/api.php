<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\UsuarioController;
use Haki\Haki;

/** @var Haki $app */

// Públicas
$app->get('/', function () {
    return ['api' => 'Haki', 'status' => 'online'];
});
$app->post('/login', [AuthController::class, 'login']);
$app->post('/refresh', [AuthController::class, 'refresh']);

// Exigem token
$app->group('/', function (Haki $app) {
    $app->get('/eu', [AuthController::class, 'eu']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/usuarios', [UsuarioController::class, 'index']);
    $app->get('/usuarios/{id}', [UsuarioController::class, 'show']);
})->auth();

// Exigem token + perfil admin
$app->group('/admin', function (Haki $app) {
    $app->get('/painel', [AdminController::class, 'painel']);
})->role('admin');
