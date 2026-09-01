<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Strips any client-supplied `include=` query parameter before it reaches
 * Paymenter core's timacdonald/json-api resources.
 *
 * Real, live-reproduced finding: `JsonApiResource`'s `included` mechanism
 * lazy-loads ANY declared relationship named in `include=`, recursively,
 * regardless of whether the top-level query already filtered hidden rows.
 * `CatalogController::categories()`/`products()` only filter `hidden` on
 * the relation they eager-load themselves — a caller-supplied
 * `include=category.products` or `include=products` still walks
 * `CategoryResource`/`ProductResource`'s declared relationships and
 * serializes a hidden product's name/description to an unauthenticated
 * caller. The same mechanism exposes internal support-agent PII (assigned
 * staff name/email) via `include=assigned_to` or `include=messages.user`
 * on a customer's own ticket.
 *
 * None of the Bridge's own responses need client-supplied `include=` —
 * every controller already eager-loads exactly the relations it embeds
 * via its own `->with(...)` calls server-side. Removing the parameter
 * entirely (rather than building and maintaining a per-route allowlist)
 * closes the whole class of leak with no functional loss and no future
 * allowlist-drift risk.
 */
class StripUnsafeIncludes
{
    public function handle(Request $request, Closure $next)
    {
        $request->query->remove('include');
        $request->request->remove('include');

        return $next($request);
    }
}
