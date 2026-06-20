<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogApiRequest
{
    /**
     * Handle an incoming request.
     *
     * Logs API requests with an is_live tag indicating whether the request
     * used the live search path (external APIs) vs. the database path.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only log JSON API responses
        if ($response->headers->get('content-type') === 'application/json') {
            $content = json_decode($response->getContent(), true);
            $isLive = $content['is_live'] ?? false;

            Log::info('API Request', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'is_live' => $isLive,
                'query_params' => $request->query->all(),
            ]);
        }

        return $response;
    }
}
