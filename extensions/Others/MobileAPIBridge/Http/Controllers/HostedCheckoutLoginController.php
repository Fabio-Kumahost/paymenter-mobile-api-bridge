<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Consumes a short-lived (5 minute) signed URL minted by
 * `CartController::checkoutSession()` and logs the exact same user in
 * via Laravel's normal web session guard, then redirects to Paymenter's
 * real cart/checkout page. Laravel's signed-URL middleware
 * (`Route::signed`) already rejects any tampered or expired link with a
 * 403 before this handler ever runs — this handler additionally
 * double-checks the signature itself as defense in depth and never
 * trusts the `user` parameter without it.
 */
class HostedCheckoutLoginController
{
    public function __invoke(Request $request)
    {
        abort_unless($request->hasValidSignature(), 403, 'This checkout link is invalid or has expired.');

        $userId = $request->integer('user');
        $nonce = (string) $request->query('nonce', '');
        abort_if($nonce === '', 403, 'This checkout link is invalid or has expired.');

        // Serialize consumption of this nonce, then remove it. Replays of the
        // exact same still-valid signed URL therefore fail, including two
        // requests racing each other on separate PHP workers. A lock-driver
        // failure (e.g. non-lockable cache backend, or a genuine timeout
        // waiting for a concurrent request holding the lock) is a real
        // possibility documented in docs/BRIDGE_INSTALL.md — fail closed
        // with the same 403 a caller sees for any other invalid/expired
        // link, never a 500 that leaks internal lock-implementation state.
        try {
            $authorizedUserId = Cache::lock('mobile-checkout-login-lock:' . hash('sha256', $nonce), 5)
                ->block(2, fn () => Cache::pull('mobile-checkout-login:' . $nonce));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            abort(403, 'This checkout link is invalid or has expired.');
        }
        abort_unless((int) $authorizedUserId === $userId && $userId > 0, 403, 'This checkout link is invalid or has expired.');

        $user = \App\Models\User::findOrFail($userId);

        Auth::login($user);
        $request->session()->regenerate();

        // Fixed internal destination: never accept a redirect target from the
        // URL, even though signed parameters cannot be modified by a caller.
        return redirect()->route('cart');
    }
}
