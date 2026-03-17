<?php

use Mpociot\ApiDoc\Http\Controller as ApiDocController;
use Illuminate\Support\Facades\Route;

$prefix = config('apidoc.laravel.docs_url', '/doc');
$middleware = config('apidoc.laravel.middleware', []);

Route::middleware($middleware)
    ->group(function () use ($prefix) {
        Route::get($prefix, [ApiDocController::class, 'html'])->name('apidoc');
        Route::get("$prefix.json", [ApiDocController::class, 'json'])->name('apidoc.json');
    });
