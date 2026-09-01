<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Support;

use Laravel\Passport\ClientRepository;

/**
 * Provisions the Bridge's dedicated public (non-confidential) PKCE OAuth
 * client, idempotently. Kept as its own small class (not inlined into the
 * extension's `installed()`) so it is independently unit-testable without
 * needing a full extension install cycle.
 */
class PublicOAuthClientProvisioner
{
    /**
     * Marker name so we can find "our" client again on a re-run — the
     * Bridge never touches or duplicates any other OAuth client an admin
     * may have created manually for other integrations.
     */
    public const CLIENT_NAME = 'PaymenterMobile (Bridge-provisioned, public PKCE)';

    public function __construct(private ClientRepository $clients) {}

    /**
     * Redirect URIs the mobile app's PKCE flow uses. A custom URL scheme
     * app-link handled entirely on-device — never a web-hosted redirect
     * that could leak the authorization code to a third party.
     */
    private const REDIRECT_URIS = [
        'de.xfabio.paymentermobile://oauth/callback',
    ];

    public function ensurePublicClientExists(): void
    {
        if ($this->findExisting() !== null) {
            return;
        }

        // confidential: false is the whole point — no secret is ever
        // generated or stored for this client, matching the hard
        // constraint that no client secret may ever ship inside the app.
        $this->clients->createAuthorizationCodeGrantClient(
            name: self::CLIENT_NAME,
            redirectUris: self::REDIRECT_URIS,
            confidential: false,
        );
    }

    public function findExisting(): ?object
    {
        return \Laravel\Passport\Passport::client()
            ->newQuery()
            ->where('name', self::CLIENT_NAME)
            ->where('revoked', false)
            ->first();
    }

    /**
     * The public client id to hand out via `GET /api/mobile/v1/meta` — a
     * client id is not a secret (RFC 6749 §2.2: "The client identifier is
     * not a secret"), so exposing it on an unauthenticated endpoint is
     * correct, not a leak.
     */
    public function publicClientID(): ?string
    {
        return $this->findExisting()?->id;
    }
}
