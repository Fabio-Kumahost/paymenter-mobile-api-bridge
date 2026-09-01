<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Own profile + a real dashboard summary — counts derived from the same
 * relations `/api/me` and the admin API use, scoped to the authenticated
 * customer. No separate "dashboard" model exists in Paymenter core; this
 * is genuinely computed from real rows, never a placeholder/static
 * response.
 */
class ProfileController
{
    public function profile(Request $request)
    {
        return new UserResource(Auth::user());
    }

    public function dashboard(Request $request)
    {
        $user = Auth::user();

        return response()->json([
            'data' => [
                'id' => (string) $user->id,
                'type' => 'dashboard-summary',
                'attributes' => [
                    'active_services' => $user->services()->where('status', 'active')->count(),
                    'open_tickets' => $user->tickets()->where('status', '!=', 'closed')->count(),
                    'unpaid_invoices' => $user->invoices()->where('status', 'pending')->count(),
                ],
            ],
        ]);
    }
}
