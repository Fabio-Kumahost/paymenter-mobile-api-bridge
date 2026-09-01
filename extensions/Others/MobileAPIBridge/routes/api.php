<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\CartController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\CatalogController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\CustomerController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\DelegatedAdminController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\HostedCheckoutLoginController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\MetaController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers\ProfileController;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Middleware\EnsureCustomerAPIEnabled;
use Paymenter\Extensions\Others\MobileAPIBridge\Http\Middleware\StripUnsafeIncludes;

// Deliberately OUTSIDE any auth middleware — this endpoint must be callable
// by an app that has no credentials yet, since its whole purpose is
// capability/version discovery before any authentication happens. It leaks
// no secrets (see MetaController's doc comment).
Route::group(['middleware' => ['api'], 'prefix' => 'api/mobile/v1'], function () {
    Route::get('/meta', MetaController::class)->name('mobile.v1.meta');
});

// Public catalog browsing — no customer session required, matches the
// real storefront's public catalog pages. Gated on enable_customer_api
// (the catalog is part of the customer-facing surface an admin can turn
// off) and StripUnsafeIncludes — see that middleware's doc comment for
// the real, live-reproduced hidden-product leak via `include=` this
// closes.
Route::group(['middleware' => ['api', EnsureCustomerAPIEnabled::class, StripUnsafeIncludes::class], 'prefix' => 'api/mobile/v1'], function () {
    Route::get('/categories', [CatalogController::class, 'categories'])->name('mobile.v1.categories');
    Route::get('/products', [CatalogController::class, 'products'])->name('mobile.v1.products');
    Route::get('/products/{product}', [CatalogController::class, 'product'])->name('mobile.v1.products.show');
});

// Customer-authenticated endpoints — same `auth:api` + `scope:profile`
// guard Paymenter core's own `/api/me` uses (Passport OAuth token from
// the app's PKCE flow), each query scoped to `Auth::id()`, never trusting
// a client-supplied user id. StripUnsafeIncludes closes the same
// include= relationship leak here too — e.g. `include=assigned_to` or
// `include=messages.user` on a customer's own ticket would otherwise
// expose an internal support agent's name/email.
Route::group(['middleware' => ['api', 'auth:api', 'scope:profile', EnsureCustomerAPIEnabled::class, StripUnsafeIncludes::class], 'prefix' => 'api/mobile/v1'], function () {
    Route::get('/profile', [ProfileController::class, 'profile'])->name('mobile.v1.profile');
    Route::get('/dashboard', [ProfileController::class, 'dashboard'])->name('mobile.v1.dashboard');

    Route::get('/orders', [CustomerController::class, 'orders'])->name('mobile.v1.orders');
    Route::get('/services', [CustomerController::class, 'services'])->name('mobile.v1.services');
    Route::get('/invoices', [CustomerController::class, 'invoices'])->name('mobile.v1.invoices');
    Route::get('/invoices/{invoice}', [CustomerController::class, 'invoice'])->name('mobile.v1.invoices.show');
    Route::get('/credits', [CustomerController::class, 'credits'])->name('mobile.v1.credits');

    Route::get('/tickets', [CustomerController::class, 'tickets'])->name('mobile.v1.tickets');
    Route::get('/tickets/{ticket}', [CustomerController::class, 'ticket'])->name('mobile.v1.tickets.show');
    Route::post('/tickets', [CustomerController::class, 'createTicket'])->name('mobile.v1.tickets.store');
    Route::post('/tickets/{ticket}/messages', [CustomerController::class, 'replyToTicket'])->name('mobile.v1.tickets.reply');

    Route::get('/cart', [CartController::class, 'show'])->name('mobile.v1.cart');
    Route::post('/cart/items', [CartController::class, 'addItem'])->name('mobile.v1.cart.add');
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem'])->name('mobile.v1.cart.remove');
    Route::post('/cart/checkout-session', [CartController::class, 'checkoutSession'])->name('mobile.v1.cart.checkout-session');
});

// Delegated admin mode: reports the caller's REAL server-verified admin
// capabilities — never trusted from a client-side claim. Deliberately its
// own group: gated by `enable_delegated_admin_api`
// (`DelegatedAdminController` itself, independent of `enable_customer_api`
// — an operator can run delegated admin without the general customer API).
Route::group(['middleware' => ['api', 'auth:api', 'scope:profile'], 'prefix' => 'api/mobile/v1'], function () {
    Route::get('/admin/capabilities', [DelegatedAdminController::class, 'capabilities'])->name('mobile.v1.admin.capabilities');
});

// Signed, short-lived hosted-checkout login link (consumed by the system
// browser, never by the app's own network layer) — protected by Laravel's
// signed-URL middleware, not by the OAuth guard, since the browser tab
// opening it has no Bearer token to send. Also gated on
// enable_customer_api: this route is reachable only via a nonce minted by
// CartController::checkoutSession(), itself already behind that gate, but
// checked again here in case a stale/leaked signed link outlives a
// mid-session config change.
Route::get('/mobile/v1/checkout-login', HostedCheckoutLoginController::class)
    ->middleware(['web', 'signed', EnsureCustomerAPIEnabled::class])
    ->name('mobile.v1.bridge-login');
