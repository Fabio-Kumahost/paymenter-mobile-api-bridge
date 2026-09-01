<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Enforces the `enable_customer_api` extension setting for every
 * customer-facing Bridge route. Previously only `MetaController` read
 * this flag (to decide which `features` to advertise) — every actual
 * controller (Profile/Customer/Cart/Catalog) ignored it entirely, so an
 * admin who unchecked "Kunden-API aktivieren" believing it locks the API
 * was wrong; the routes stayed fully live. Mirrors
 * `DelegatedAdminController`'s existing `enable_delegated_admin_api`
 * check: read fresh from the extension's settings on every request (no
 * request-lifetime caching), fail closed (404) on any ambiguous/missing
 * config value.
 */
class EnsureCustomerAPIEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $extension = \App\Models\Extension::query()
            ->where('extension', 'MobileAPIBridge')
            ->first();
        $configuredValue = $extension?->settings
            ->firstWhere('key', 'enable_customer_api')?->value ?? true;
        $enabled = filter_var(
            $configuredValue,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        abort_unless($enabled, 404);

        return $next($request);
    }
}
