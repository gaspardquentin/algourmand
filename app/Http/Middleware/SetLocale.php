<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Set the application locale from the Accept-Language header, falling back to English.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supportedLocales */
        $supportedLocales = config('algourmand.supported_locales');

        app()->setLocale($request->getPreferredLanguage($supportedLocales) ?? config('app.fallback_locale'));

        return $next($request);
    }
}
