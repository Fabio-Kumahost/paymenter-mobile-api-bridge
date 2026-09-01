<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\ClientRepository;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PublicOAuthClientProvisioner;

/**
 * Mobile API Bridge — separately installable Paymenter extension providing
 * the customer-facing REST surface Paymenter's core API does not have
 * (see docs/API_MATRIX.md at the repo root for the verified, sourced
 * breakdown of what core provides vs. what this Bridge adds).
 *
 * Prefix: /api/mobile/v1. Everything under that prefix is implemented on
 * top of Paymenter's real Eloquent models, Policies, and Events — never a
 * re-implementation of business logic, and never trusts client-submitted
 * prices/taxes for anything that touches money.
 *
 * @link https://docs.paymenter.org/development/extensions
 */
#[ExtensionMeta(
    name: 'Mobile API Bridge',
    description: 'Provides the customer-facing REST API surface (profile, catalog, orders, services, invoices, tickets, hosted checkout) that PaymenterMobile and other native clients need but Paymenter core does not expose.',
    version: '0.1.0',
    author: 'xfabio.de',
)]
class MobileAPIBridge extends Extension
{
    public function __construct(public $config = []) {}

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'enable_customer_api',
                'label' => 'Kunden-API aktivieren',
                'type' => 'checkbox',
                'default' => true,
                'description' => 'Stellt die Kunden-Endpunkte unter /api/mobile/v1/* bereit (Profil, Katalog, Bestellungen, Services, Rechnungen, Tickets, Checkout).',
            ],
            [
                'name' => 'enable_delegated_admin_api',
                'label' => 'Delegierte Admin-API aktivieren',
                'type' => 'checkbox',
                'default' => false,
                'description' => 'Erlaubt Kunden mit echten Admin-Berechtigungen, denselben OAuth-Kundentoken für eingeschränkte Admin-Funktionen zu nutzen — serverseitig gegen die echte Paymenter-Rolle geprüft, niemals aus einem Client-Flag abgeleitet.',
            ],
        ];
    }

    /**
     * Provisions the dedicated PUBLIC (non-confidential) PKCE-capable OAuth
     * client on first install. Uses Passport's own `ClientRepository` —
     * this is officially supported Passport 13.x API
     * (`createAuthorizationCodeGrantClient($name, $redirectUris,
     * confidential: false)`), NOT a custom auth proxy. Verified against
     * the real `laravel/passport` 13.x source (`ClientRepository::create()`
     * accepts `confidential: bool`) and Paymenter's own composer.json
     * pinning `laravel/passport: ^13.7`. league/oauth2-server 9.x (the
     * Passport 13 dependency) enforces PKCE for public clients by default,
     * so no extra PKCE plumbing is needed here — Paymenter's existing
     * `/oauth/authorize` and `/api/oauth/token` already do the right thing
     * for a client provisioned this way.
     *
     * Idempotent: re-running `installed()` (e.g. on a re-install or
     * version bump) never creates a duplicate client — it looks for an
     * existing, non-revoked client with this Bridge's marker name first.
     */
    public function installed()
    {
        ExtensionHelper::runMigrations('extensions/Others/MobileAPIBridge/database/migrations');

        try {
            $provisioner = new PublicOAuthClientProvisioner(new ClientRepository);
            $provisioner->ensurePublicClientExists();
        } catch (\Throwable $e) {
            // Never let OAuth client provisioning failure silently break
            // the rest of the extension install — log it loudly so an
            // admin notices, and GET /api/mobile/v1/meta will correctly
            // report auth_methods as empty until this is resolved.
            Log::error('MobileAPIBridge: failed to provision public OAuth client', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function uninstalled()
    {
        ExtensionHelper::rollbackMigrations('extensions/Others/MobileAPIBridge/database/migrations');
    }

    public function boot()
    {
        require __DIR__ . '/routes/api.php';
    }
}
