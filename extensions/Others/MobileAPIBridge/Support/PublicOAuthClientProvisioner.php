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

        // Different Passport 13.x releases expose different
        // `ClientRepository` APIs for this — verified live against three
        // real, differently-versioned installations (a live production
        // instance, this project's own Docker test server, and this
        // repo's own composer.json-pinned test harness):
        //
        //   - Most releases seen: public
        //     `createAuthorizationCodeGrantClient(string $name, array
        //     $redirectUris, bool $confidential = true, ...)` — preferred
        //     whenever it's public, since its signature has been stable.
        //   - One older release (no such helper exists yet) has only a
        //     `create($userId, $name, $redirect, $provider = null,
        //     $personalAccess = false, $password = false, $confidential =
        //     true)` method. This is the ONLY shape of `create()` this
        //     fallback has been verified against live; other Passport
        //     releases have been observed with entirely different
        //     `create()` parameter names/order/visibility, so this
        //     fallback is a best-effort for the one real case seen where
        //     it's actually needed, not a universal adapter for every
        //     possible `create()` shape.
        //
        // `confidential: false` is the one constant across every path:
        // no secret is ever generated for this client, matching the hard
        // constraint that no client secret may ever ship inside the app.
        $reflection = new \ReflectionClass($this->clients);

        if ($reflection->hasMethod('createAuthorizationCodeGrantClient')) {
            $method = $reflection->getMethod('createAuthorizationCodeGrantClient');
            if ($method->isPublic()) {
                $this->clients->createAuthorizationCodeGrantClient(
                    name: self::CLIENT_NAME,
                    redirectUris: self::REDIRECT_URIS,
                    confidential: false,
                );

                return;
            }
        }

        if ($reflection->hasMethod('create')) {
            $method = $reflection->getMethod('create');
            $method->setAccessible(true);
            // `redirect` on this API shape is always a single string —
            // `Bridge\Client::__construct()` does `explode(',',
            // $redirectUri)` on it, never accepts an array.
            $method->invoke(
                $this->clients,
                null,
                self::CLIENT_NAME,
                implode(',', self::REDIRECT_URIS),
                null,
                false,
                false,
                false,
            );

            return;
        }

        throw new \RuntimeException(
            'MobileAPIBridge: installed laravel/passport ClientRepository exposes neither '
            . 'createAuthorizationCodeGrantClient() nor create() — cannot provision the public OAuth client.'
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
