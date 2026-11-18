<?php

declare(strict_types=1);

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\ProductApiController;
use App\Http\Controllers\Api\TransactionApiController;
use App\Http\Controllers\Api\UserApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\TransactionController;

return [
    AuthController::class,
    ProductsController::class,
    AdminUserController::class,
    TransactionController::class,
    AuthApiController::class,
    ProductApiController::class,
    UserApiController::class,
    TransactionApiController::class,
];
