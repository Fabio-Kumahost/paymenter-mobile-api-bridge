# Changelog

## 0.1.1

- Fix: `PublicOAuthClientProvisioner::ensurePublicClientExists()` no
  longer unconditionally calls `ClientRepository::createAuthorizationCodeGrantClient()`.
  That method does not exist on every installed `laravel/passport` 13.x
  patch release — verified live via SSH reflection against a real
  production Paymenter instance, where only the older `create()` method
  was present. The provisioner now detects, via reflection, which method
  the installed `ClientRepository` actually exposes and uses it, falling
  back to `create()` (including protected/older positional signatures)
  when the newer helper isn't available, and raising a clear
  `RuntimeException` instead of silently failing when neither exists.
- This fixes a real-world bug where `oauth_public_client_id` stayed
  `null` forever on some Paymenter installations even though the
  extension was installed correctly, making the app permanently report
  "no Bridge" for that instance.
- Compatible with more Passport 13.x patch versions as a result; no
  behavior change for installations where the newer method was already
  present.

## 0.1.0

- Initial release.
