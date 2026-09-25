<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Not found for what only a scanner asks for — a path segment starting with a
 * dot (.env, .git; .well-known aside) or a file of secrets, logs, dumps or
 * backups — before any route or session runs.
 */
class BlockSensitivePaths
{
    private const PATTERN = '#(^|/)\.(?!well-known(/|$))|\.(env|log|sql|bak|old|orig|swp|dist)$|~$#i';

    /** Answer 404 for a sensitive path, else pass the request on. */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(preg_match(self::PATTERN, rawurldecode($request->getPathInfo())) === 1, 404);

        return $next($request);
    }
}
