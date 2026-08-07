<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| ASIDS is an API plus a single-page application, so there is exactly one web route: the
| SPA shell. Every path that is not the API, not a build asset and not the health check
| returns the shell, and Vue Router decides what to render.
|
| The catch-all is registered last and deliberately excludes `api` and `ops` so a
| mistyped API path returns a JSON 404 from the API rather than an HTML page that a
| client's JSON parser cannot read.
|
| `sanctum/csrf-cookie` is registered by Sanctum itself and must remain reachable: the
| SPA calls it before its first stateful request.
|
*/

Route::get('/{any}', static fn (): View => view('app'))
    ->where('any', '^(?!api|ops|storage|build|up).*$')
    ->name('spa');
