<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression guards for security invariants whose runtime paths
 * require Paymenter's full database/session stack. The Bridge's standalone
 * PHPUnit harness intentionally has no application bootstrap or database; these
 * tests still ensure a future refactor cannot silently remove the critical
 * relation-scoped plan lookup or one-time checkout nonce consumption.
 */
class BridgeSecurityInvariantTest extends TestCase
{
    private function controllerSource(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/Http/Controllers/' . $name . '.php';
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Controller source must be readable: ' . $path);

        return $source;
    }

    public function test_cart_plan_is_resolved_through_its_product_relation(): void
    {
        $source = $this->controllerSource('CartController');

        self::assertStringContainsString(
            '$product->plans()->whereKey($validated[\'plan_id\'])->firstOrFail()',
            $source,
        );
        self::assertStringContainsString("'plan_id' => \$plan->id", $source);
        self::assertStringNotContainsString("'plan_id' => \$validated['plan_id']", $source);
    }

    public function test_checkout_login_nonce_is_single_use_and_redirect_is_fixed(): void
    {
        $cart = $this->controllerSource('CartController');
        $login = $this->controllerSource('HostedCheckoutLoginController');

        self::assertStringContainsString("Cache::put('mobile-checkout-login:' . \$nonce", $cart);
        self::assertStringContainsString("'nonce' => \$nonce", $cart);
        self::assertStringContainsString("Cache::lock('mobile-checkout-login-lock:'", $login);
        self::assertStringContainsString("Cache::pull('mobile-checkout-login:' . \$nonce)", $login);
        self::assertStringContainsString("return redirect()->route('cart');", $login);
        self::assertStringNotContainsString("query('redirect'", $login);
    }

    public function test_public_category_relationship_filters_hidden_products(): void
    {
        $source = $this->controllerSource('CatalogController');

        self::assertStringContainsString(
            "->with(['products' => fn (\$query) => \$query->where('hidden', false)])",
            $source,
        );
        self::assertStringNotContainsString("->with('products')", $source);
    }

    public function test_meta_advertises_only_implemented_feature_groups_without_placeholders(): void
    {
        $source = $this->controllerSource('MetaController');

        foreach (['profile', 'dashboard', 'catalog', 'orders', 'services', 'invoices', 'credits', 'tickets', 'cart', 'hosted_checkout'] as $feature) {
            self::assertStringContainsString("'{$feature}'", $source);
        }
        self::assertStringNotContainsString('placeholder', strtolower($source));
    }

    public function test_delegated_admin_endpoint_enforces_extension_setting(): void
    {
        $source = $this->controllerSource('DelegatedAdminController');

        self::assertStringContainsString("enable_delegated_admin_api", $source);
        self::assertStringContainsString('abort_unless', $source);
    }

    /**
     * Regression guard for the LIVE-reproduced hidden-product /
     * support-agent-PII leak via `include=` (timacdonald/json-api lazily
     * resolves ANY declared relationship named in `include=`, regardless
     * of a controller's own top-level `hidden` filter). Verifies every
     * customer-facing/public Bridge route group applies
     * `StripUnsafeIncludes`, not just that a single controller's SQL
     * string looks right — the previous version of this test file only
     * checked the top-level query string, which gave false confidence
     * while the actual `include=` leak was still live.
     */
    public function test_bridge_routes_strip_unsafe_includes(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        self::assertNotFalse($routes);

        self::assertStringContainsString('StripUnsafeIncludes::class', $routes);
        // Both the public catalog group and the authenticated customer
        // group must apply it — the catalog leak (hidden products) is
        // public/unauthenticated, the PII leak (assigned_to/messages.user)
        // is on the authenticated customer group.
        self::assertSame(2, substr_count($routes, 'StripUnsafeIncludes::class'));
    }

    public function test_strip_unsafe_includes_removes_the_query_parameter(): void
    {
        $path = dirname(__DIR__, 2) . '/Http/Middleware/StripUnsafeIncludes.php';
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Middleware source must be readable: ' . $path);
        self::assertStringContainsString("query->remove('include')", $source);
        self::assertStringContainsString("request->remove('include')", $source);
    }

    /**
     * Regression guard for H1: `enable_customer_api` was previously read
     * only by MetaController's feature-list logic — every actual
     * customer-facing controller ignored it, so disabling it in the admin
     * UI did nothing. Now every customer route group (public catalog +
     * authenticated customer + hosted-checkout-login) must apply
     * `EnsureCustomerAPIEnabled`.
     */
    public function test_bridge_customer_routes_enforce_enable_customer_api(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        self::assertNotFalse($routes);

        self::assertSame(3, substr_count($routes, 'EnsureCustomerAPIEnabled::class'));
    }
}
