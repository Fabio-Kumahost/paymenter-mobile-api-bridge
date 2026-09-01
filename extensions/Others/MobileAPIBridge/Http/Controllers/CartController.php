<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Cart + hosted checkout. Deliberately does NOT reimplement Paymenter's
 * pricing/coupon/config-option/tax logic (that logic lives in
 * `App\Models\CartItem::price()` and is genuinely complex — config
 * option children, coupons, exclusive tax). Instead this reuses the same
 * `App\Models\Cart`/`CartItem` models Paymenter's own web cart uses, so
 * pricing is always computed by Paymenter core itself, never duplicated
 * or approximated here.
 *
 * Responses are a deliberately flat, hand-shaped JSON (not the JSON:API
 * envelope used elsewhere) — a cart item's real price/availability comes
 * from a computed `price()` accessor with no stable underlying column,
 * so embedding it directly here is simpler and less error-prone for the
 * client than JSON:API `included` relationship resolution.
 *
 * Checkout hands back a short-lived (5 minute) SIGNED URL to Paymenter's
 * own real checkout page, pre-authenticated via a one-time login token —
 * never a payment form rendered inside the app, never card data touching
 * the app or the Bridge. The app opens this URL in the system browser
 * (SFSafariViewController/ASWebAuthenticationSession), matching the hard
 * "hosted checkout session, no WKWebView, no card data in-app" rule.
 */
class CartController
{
    /**
     * Same limits Paymenter core's own `App\Classes\Cart::add()` enforces
     * on the web cart — the Bridge bypassed both entirely (M1) by calling
     * `$cart->items()->create()` directly instead of going through
     * `Cart::add()`. Mirrored here rather than calling the core helper
     * directly because it always resolves the ULID cookie-based cart,
     * which does not exist in an OAuth-authenticated API request.
     */
    private const MAX_CART_ITEMS = 15;

    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    private const RATE_LIMIT_DECAY_SECONDS = 60;

    public function show(Request $request)
    {
        $cart = $this->cartForUser();

        return $this->cartResponse($cart);
    }

    public function addItem(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'config_options' => ['nullable', 'array'],
        ]);

        $rateLimitKey = 'mobile-cart-add:user:' . Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX_ATTEMPTS)) {
            abort(429, 'You are adding items too quickly. Please wait a moment and try again.');
        }
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_SECONDS);

        $product = Product::findOrFail($validated['product_id']);
        abort_if($product->hidden, 404);

        // Security invariant: a plan id is meaningful only in the context of
        // its owning product. Validating the two ids independently would let a
        // caller pair a cheap plan from product A with product B, corrupting
        // both pricing and downstream provisioning. Resolve it through the
        // product relation so a mismatched id is rejected with 404.
        $plan = $product->plans()->whereKey($validated['plan_id'])->firstOrFail();

        $cart = $this->cartForUser();
        abort_if($cart->items()->count() >= self::MAX_CART_ITEMS, 422, 'Your cart cannot contain more than ' . self::MAX_CART_ITEMS . ' items.');
        $cart->items()->create([
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'quantity' => $validated['quantity'] ?? 1,
            'config_options' => $validated['config_options'] ?? [],
        ]);

        return $this->cartResponse($cart->fresh());
    }

    public function removeItem(Request $request, int $item)
    {
        $cart = $this->cartForUser();
        $cart->items()->where('id', $item)->firstOrFail()->delete();

        return $this->cartResponse($cart->fresh());
    }

    /**
     * Returns a one-time, 5-minute-lived signed URL to Paymenter's own
     * real cart/checkout page, pre-authenticated for the calling
     * customer. The app opens this in the system browser — never
     * embeds it, never inspects the payment form.
     */
    public function checkoutSession(Request $request)
    {
        $cart = $this->cartForUser();
        abort_if($cart->items()->count() === 0, 422, 'Cart is empty.');

        // The signed URL is also single-use. A signature+expiry alone only
        // prevents tampering; it does not prevent replay during the five-minute
        // validity window. Store a random nonce server-side and atomically
        // consume it in HostedCheckoutLoginController.
        $nonce = Str::random(64);
        Cache::put('mobile-checkout-login:' . $nonce, (int) Auth::id(), now()->addMinutes(5));

        $url = URL::temporarySignedRoute(
            'mobile.v1.bridge-login',
            now()->addMinutes(5),
            ['user' => Auth::id(), 'nonce' => $nonce]
        );

        return response()->json([
            'checkout_url' => $url,
            'expires_in' => 300,
        ]);
    }

    private function cartResponse(Cart $cart)
    {
        $cart->load('items.plan', 'items.product');

        return response()->json([
            'data' => [
                'id' => (string) $cart->id,
                'type' => 'carts',
                'attributes' => [
                    'currency_code' => $cart->currency_code,
                ],
                'items' => $cart->items->map(function ($item) {
                    $price = $item->price;

                    return [
                        'id' => (string) $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'plan_id' => $item->plan_id,
                        'plan_name' => $item->plan?->name,
                        'quantity' => $item->quantity,
                        'price' => $price->price !== null ? (string) $price->price : null,
                        'setup_fee' => $price->setup_fee !== null ? (string) $price->setup_fee : null,
                        'currency_code' => $price->currency?->code,
                        'available' => $price->price !== null,
                    ];
                }),
            ],
        ]);
    }

    private function cartForUser(): Cart
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => Auth::id()],
            ['currency_code' => session('currency', config('settings.default_currency'))]
        );

        return $cart;
    }
}
