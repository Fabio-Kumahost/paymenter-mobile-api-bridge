<?php

namespace Paymenter\Extensions\Others\MobileAPIBridge\Support;

use App\Models\Service;

/**
 * Resolves provider-specific infrastructure details (IP, hostname, OS,
 * location, status) for a service — WITHOUT the Bridge hard-depending on
 * any specific server-provisioning extension. Provider extensions
 * (Proxmox, Pterodactyl, ...) are optional installs; the Bridge must
 * never crash or fabricate a response shape when a service's provider
 * isn't one it knows how to read, or isn't installed at all on this
 * Paymenter instance.
 *
 * Currently supports: Proxmox (`Paymenter\Extensions\Servers\Proxmox`).
 * Verified against the real extension's schema on a live instance:
 * `ext_proxmox_servers` (model `...\Proxmox\Models\Server`), keyed by
 * `service_id`, with `hostname`, `status`, `primary_ipv4`/`primary_ipv6`
 * (both relations to an `IPAddress` model, never plain strings), `os_id`
 * (via an `os()` relation), `node_id` (via a `node()` relation), and
 * `last_password` — which this resolver never reads or exposes.
 *
 * Adding a new provider: add a `resolveViaXyz()` method following the
 * same defensive pattern (class_exists guard, never throw) and call it
 * from `resolve()`. Never let one provider's absence/failure affect
 * another's.
 */
class ServerDetailsResolver
{
    private const PROXMOX_SERVER_MODEL = 'Paymenter\\Extensions\\Servers\\Proxmox\\Models\\Server';

    /**
     * @return array<string, mixed>|null Null when no known provider
     *   adapter produced anything for this service — never an error, and
     *   never a partially-shaped array (either the full known shape, or
     *   null).
     */
    public static function resolve(Service $service): ?array
    {
        return self::resolveViaProxmox($service);
    }

    private static function resolveViaProxmox(Service $service): ?array
    {
        if (! class_exists(self::PROXMOX_SERVER_MODEL)) {
            // Extension not installed on this Paymenter instance at all —
            // the normal case for the vast majority of installs. Not an
            // error condition.
            return null;
        }

        try {
            $modelClass = self::PROXMOX_SERVER_MODEL;
            /** @var \Illuminate\Database\Eloquent\Model|null $server */
            $server = $modelClass::query()
                ->where('service_id', $service->id)
                ->first();
        } catch (\Throwable $e) {
            // The extension class existing doesn't guarantee its table
            // exists/matches this exact schema (e.g. mid-migration, or a
            // future extension version this resolver hasn't been
            // updated for). Degrade to "no server details" rather than
            // 500ing the whole service-detail endpoint over an optional,
            // provider-specific extra.
            return null;
        }

        if ($server === null) {
            // Extension installed, but this particular service isn't
            // provisioned through it (e.g. a non-VPS product).
            return null;
        }

        return [
            'provider' => 'proxmox',
            'hostname' => $server->hostname ?? null,
            'status' => $server->status ?? null,
            'primary_ipv4' => self::resolveIPAddress($server, 'primaryIpv4'),
            'primary_ipv6' => self::resolveIPAddress($server, 'primaryIpv6'),
            'os_name' => self::safeRelationAttribute($server, 'os', 'name'),
            'location_name' => self::safeRelationAttribute($server, 'node', 'name'),
            // Deliberately excluded: `last_password` and any other
            // credential field. This resolver is read-only infrastructure
            // display data, never secrets.
        ];
    }

    /**
     * `primaryIpv4()`/`primaryIpv6()` are the actual Eloquent relation
     * METHOD names on `...\Proxmox\Models\Server` (belongsTo IPAddress) —
     * NOT the same as the `primary_ipv4`/`primary_ipv6` snake_case
     * column names on the same row (those hold the raw foreign-key
     * integer). Eloquent's magic `__get` only resolves a relation when
     * the accessed property name exactly matches a defined relation
     * method; passing the snake_case column name here silently falls
     * through to plain attribute access instead, returning the raw FK
     * id (e.g. `488`) rather than the resolved `IPAddress` model — this
     * was live-reproduced against a real service on pm.kumahost.eu
     * (`primary_ipv4` attribute value was int `488`, `ipAddresses()`
     * relation had a real matching row), which made every response
     * report `primary_ipv4: null` because `resolveIPAddress` couldn't
     * find `->address`/`->ip`/`->ip_address` on a raw integer. Callers
     * below therefore pass the correct camelCase relation name.
     */
    private static function resolveIPAddress($server, string $relation): ?string
    {
        try {
            $address = $server->{$relation};
        } catch (\Throwable $e) {
            return null;
        }

        if ($address === null) {
            return null;
        }

        // The exact attribute name for the address string on the
        // IPAddress model varies by extension version; try the common
        // candidates rather than assuming one and silently returning
        // null for every real install that uses a different one.
        foreach (['address', 'ip', 'ip_address'] as $attribute) {
            if (isset($address->{$attribute})) {
                return (string) $address->{$attribute};
            }
        }

        return null;
    }

    private static function safeRelationAttribute($model, string $relation, string $attribute): ?string
    {
        try {
            $related = $model->{$relation};
        } catch (\Throwable $e) {
            return null;
        }

        if ($related === null || ! isset($related->{$attribute})) {
            return null;
        }

        return (string) $related->{$attribute};
    }
}
