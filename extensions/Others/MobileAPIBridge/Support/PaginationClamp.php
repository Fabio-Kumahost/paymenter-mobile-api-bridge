<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Support;

use Illuminate\Http\Request;

/**
 * Clamps client-supplied pagination parameters to a safe range. Previously
 * every controller only capped `per_page` at 100 (`min($request->integer(...),
 * 100)`) with no lower bound — a caller sending `per_page=0` or a negative
 * value reaches Eloquent's paginator with a nonsensical page size (0 causes
 * a division-by-zero-adjacent infinite-page situation in some paginator
 * paths; negative values are equally undefined). `page` had no bound at
 * all. Both are clamped to at least 1 here, matching the documented
 * `per_page` (max 100) contract from docs/API_MATRIX.md.
 */
final class PaginationClamp
{
    public static function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return max(1, min($request->integer('per_page', $default), $max));
    }

    public static function page(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }
}
