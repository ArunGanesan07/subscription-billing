<?php

namespace App\Http\Middleware;

use App\Services\TenantLookup;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a merchant from `Authorization: Bearer <api key>`.
 *
 * The resolved merchant becomes $request->user(), so the rest of the stack
 * (rate limiter, tenant-scoped route bindings, controllers) has one place
 * to ask "who is calling?". Lookup is cached; see TenantLookup.
 *
 * Implementing AuthenticatesRequests puts this middleware first in Laravel's
 * middleware priority list: ahead of ThrottleRequests (so limits are keyed per
 * merchant, not per IP) and ahead of SubstituteBindings (so route bindings can
 * be tenant-scoped).
 */
class AuthenticateMerchant implements AuthenticatesRequests
{
    public function __construct(private readonly TenantLookup $lookup) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken();
        $merchant = $key ? $this->lookup->merchantByApiKey($key) : null;

        if ($merchant === null) {
            return response()->json([
                'message' => 'Missing or invalid API key.',
                'error' => 'unauthenticated',
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $request->setUserResolver(fn () => $merchant);

        return $next($request);
    }
}
