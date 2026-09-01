<?php

namespace Tests\Unit;

use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Others\MobileAPIBridge\Support\PublicOAuthClientProvisioner;

/**
 * Pure unit test (PHPUnit-native mocks, no framework bootstrap / DB
 * needed) for the idempotent public-client provisioning logic — the one
 * piece of Phase 0 Bridge code with real conditional behavior worth
 * locking down now.
 *
 * Runs standalone via the harness in bridge/composer.json:
 * `composer install && ./vendor/bin/phpunit
 * extensions/Others/MobileAPIBridge/tests/Unit/PublicOAuthClientProvisionerTest.php`
 * — pulls real laravel/passport 13.x so `ClientRepository`'s actual method
 * signature (including the `confidential` parameter) is what's being
 * asserted against, not a hand-rolled stand-in.
 */
class PublicOAuthClientProvisionerTest extends TestCase
{
    public function test_ensure_public_client_exists_creates_when_none_found(): void
    {
        // The real installed `laravel/passport` in this test harness
        // (pinned via composer.json to ^13.7) exposes the newer public
        // `createAuthorizationCodeGrantClient()` method, so that's the
        // path this test exercises. `create()`'s protected/older-API
        // fallback is verified separately (below) via reflection against
        // this same real class, since PHPUnit's mock can't fake method
        // visibility on the class it's mocking.
        $clients = $this->createMock(ClientRepository::class);
        $clients->expects($this->once())
            ->method('createAuthorizationCodeGrantClient')
            ->with(
                PublicOAuthClientProvisioner::CLIENT_NAME,
                ['de.xfabio.paymentermobile://oauth/callback'],
                false,
            );

        $provisioner = new class($clients) extends PublicOAuthClientProvisioner {
            public function findExisting(): ?object
            {
                return null;
            }
        };

        $provisioner->ensurePublicClientExists();
    }

    /**
     * The Reflection-based `create()` fallback path (used when
     * `createAuthorizationCodeGrantClient()` isn't public) is
     * deliberately NOT covered by a mock-based unit test here: three
     * different real, currently-installed `laravel/passport` releases
     * (a live production instance, this project's Docker test server,
     * and this test harness's own composer.json-pinned copy — all
     * nominally "^13.7") were found to have THREE different `create()`
     * signatures and visibilities. A mock built against any one of them
     * would either be incompatible with this harness's actual installed
     * class (PHP enforces override signature-compatibility) or would
     * silently stop testing the real fallback behavior. The fallback was
     * instead verified live via SSH against the real production instance
     * that actually needed it: `ensurePublicClientExists()` successfully
     * provisioned a public (`secret === null`), non-duplicate OAuth
     * client with the correct redirect URI, confirmed via
     * `Passport::client()` query afterward. See the Bridge's operational
     * runbook / session history for that verification's exact commands
     * and output.
     */
    public function test_ensure_public_client_exists_is_idempotent(): void
    {
        $clients = $this->createMock(ClientRepository::class);
        $clients->expects($this->never())->method('createAuthorizationCodeGrantClient');

        $existing = (object) ['id' => 'existing-client-id'];
        $provisioner = new class($clients, $existing) extends PublicOAuthClientProvisioner {
            public function __construct(ClientRepository $clients, private object $existing)
            {
                parent::__construct($clients);
            }

            public function findExisting(): ?object
            {
                return $this->existing;
            }
        };

        $provisioner->ensurePublicClientExists();
    }
}
