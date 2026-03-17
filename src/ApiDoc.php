<?php

namespace Mpociot\ApiDoc;

use Illuminate\Support\Facades\Route;
use Mpociot\ApiDoc\Http\Controller as ApiDocController;

class ApiDoc
{
    /**
     * Binds the ApiDoc routes into the controller.
     *
     * @deprecated Use autoload routes instead (`config/apidoc.php`: `laravel > autoload`).
     *
     * @param string $path
     */
    public static function routes($path = '/doc')
    {
        Route::prefix($path)
            ->middleware(static::middleware())
            ->group(function () {
                Route::get('/', [ApiDocController::class, 'html'])->name('apidoc');
                Route::get('.json', [ApiDocController::class, 'json'])->name('apidoc.json');
            });
    }

    /**
     * Get the middlewares for Laravel routes.
     *
     * @return array
     */
    protected static function middleware()
    {
        return config('apidoc.laravel.middleware', []);
    }
}
