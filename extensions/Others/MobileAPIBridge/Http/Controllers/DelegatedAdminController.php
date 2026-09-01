<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Delegated admin mode: reports which admin capabilities the CURRENTLY
 * OAuth-authenticated customer session actually has, verified via
 * Paymenter's real `User::hasPermission()` (which reads the real
 * `Role::permissions` array from the database) — never derived from a
 * boolean flag, an email domain, or anything cached/guessed client-side.
 * Mirrors the exact permission strings Paymenter's own admin
 * `*Policy` classes check (verified against `app/Policies/UserPolicy.php`
 * etc.), so this reports precisely what the real admin UI would allow.
 */
class DelegatedAdminController
{
    /**
     * One permission string per `AdminCapability` case the iOS app knows
     * about. Verified against the REAL Admin API authorization gate
     * (`App\Http\Requests\Api\Admin\*\Get*Request::$permission`, checked
     * by `AdminApiRequest::authorize()` as `'admin.' . $permission`) —
     * NOT the Filament admin-UI `*Policy::viewAny()` strings previously
     * used here, which are a different, unrelated permission namespace
     * (e.g. `admin.credits.viewAny` is a UI-only permission; the actual
     * `/api/v1/admin/credits` endpoint the app calls checks
     * `admin.credits.view`). `invoice-items`/`ticket-messages` deliberately
     * use an underscore here (`invoice_items`/`ticket_messages`) — that is
     * the real permission string in the corresponding `GetXRequest`
     * class, distinct from the hyphenated URL/route segment.
     *
     * `affiliates` uses `admin.affiliates.view` — verified against the
     * separately installable Affiliates extension's real source
     * (`GetAffiliatesRequest::$permission = 'affiliates.view'`, same
     * `AdminApiRequest` pattern as every core resource). If that
     * extension isn't installed, `$user->hasPermission(...)` simply
     * returns false for a permission string that was never granted —
     * no special-casing needed, this already reports honestly as
     * unavailable.
     */
    private const CAPABILITY_PERMISSIONS = [
        'users' => 'admin.users.view',
        'orders' => 'admin.orders.view',
        'services' => 'admin.services.view',
        'credits' => 'admin.credits.view',
        'invoices' => 'admin.invoices.view',
        'invoice-items' => 'admin.invoice_items.view',
        'tickets' => 'admin.tickets.view',
        'ticket-messages' => 'admin.ticket_messages.view',
        'products' => 'admin.products.view',
        'categories' => 'admin.categories.view',
        'affiliates' => 'admin.affiliates.view',
    ];

    public function capabilities(Request $request)
    {
        $extension = \App\Models\Extension::query()
            ->where('extension', 'MobileAPIBridge')
            ->first();
        $configuredValue = $extension?->settings
            ->firstWhere('key', 'enable_delegated_admin_api')?->value ?? false;
        $enabled = filter_var(
            $configuredValue,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
        abort_unless($enabled, 404);

        $user = Auth::user();

        $results = [];
        foreach (self::CAPABILITY_PERMISSIONS as $capability => $permission) {
            $results[$capability] = $user->hasPermission($permission);
        }

        return response()->json([
            'data' => [
                'type' => 'delegated-admin-capabilities',
                'attributes' => [
                    'is_admin' => $user->role !== null,
                    'role_name' => $user->role?->name,
                    'capabilities' => $results,
                ],
            ],
        ]);
    }
}
