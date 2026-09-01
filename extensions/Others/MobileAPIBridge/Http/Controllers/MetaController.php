<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Laravel\Passport\ClientRepository;
use Paymenter\Extensions\Others\MobileAPIBridge\MobileAPIBridge;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PublicOAuthClientProvisioner;

/**
 * GET /api/mobile/v1/meta — public, unauthenticated, no secrets.
 *
 * Deliberately returns ONLY information that is safe to hand to an
 * unauthenticated caller: the Bridge's own version, which Paymenter core
 * versions it has been verified against, which features are actually
 * enabled on THIS instance (never hardcoded — read from real config), which
 * auth methods are available, and the public OAuth client id (not a
 * secret — see `PublicOAuthClientProvisioner::publicClientID()` doc
 * comment for why that's safe per RFC 6749).
 */
class MetaController extends Controller
{
    /** Bridge versions this endpoint's response shape is compatible with. */
    private const BRIDGE_VERSION = '0.1.0';

    /**
     * Paymenter core versions this Bridge has actually been verified
     * against — never a guess, updated only after real compatibility
     * testing against that version.
     */
    private const COMPATIBLE_PAYMENTER_VERSIONS = ['1.5.5', '1.5.6', '1.5.7', '1.5.8'];

    public function __invoke(): JsonResponse
    {
        $extension = \App\Models\Extension::query()
            ->where('extension', 'MobileAPIBridge')
            ->first();

        $config = $extension
            ? $extension->settings->pluck('value', 'key')->toArray()
            : [];

        $customerAPIEnabled = filter_var(
            $config['enable_customer_api'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
        $delegatedAdminEnabled = filter_var(
            $config['enable_delegated_admin_api'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        $features = $customerAPIEnabled
            ? [
                'profile',
                'dashboard',
                'catalog',
                'orders',
                'services',
                'invoices',
                'credits',
                'tickets',
                'cart',
                'hosted_checkout',
            ]
            : [];

        $authMethods = [];
        $publicClientID = null;
        try {
            $provisioner = new PublicOAuthClientProvisioner(new ClientRepository);
            $publicClientID = $provisioner->publicClientID();
            if ($publicClientID !== null) {
                $authMethods[] = 'oauth_pkce';
            }
        } catch (\Throwable) {
            // If Passport isn't fully migrated/configured yet, meta must
            // still respond (with an honest empty auth_methods list)
            // rather than 500 — this endpoint is the FIRST thing the app
            // calls to discover the instance, before anything else is
            // known to work.
        }
        if ($delegatedAdminEnabled) {
            $authMethods[] = 'delegated_admin';
        }

        return response()->json([
            'bridge_version' => self::BRIDGE_VERSION,
            'compatible_paymenter_versions' => self::COMPATIBLE_PAYMENTER_VERSIONS,
            'features' => $features,
            'auth_methods' => $authMethods,
            'oauth_public_client_id' => $publicClientID,
        ]);
    }
}
