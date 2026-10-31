<?php

declare(strict_types=1);

namespace Superroute\SLocker\Customer;

use Superroute\SApp\PartnerClient;

/**
 * The entry to the customer side of sLocker: the smart-locker operations of the partner app API
 * (open.superroute.ca/app/v1) on top of the sApp core. Needs superroute/sapp (composer require superroute/sapp); the
 * carrier client (Superroute\SLocker\Client) never loads it.
 *
 *     $locker = SLocker::customer('p_acme_parcels', clientCode: 'k3m9q2', appKey: 'sp_appkey_…');
 *     $locker->sapp->signInWithIdToken($googleIdToken);
 *     $pickups = $locker->api->platformAppPickups(status: 'ready');
 *
 *     $locker = SLocker::customer(sapp: $sr);      // share the sign-in of an sApp client the app already has
 */
final class SLocker
{
    /**
     * Standalone: the app code and any named argument of Superroute\SApp\PartnerClient (clientCode, appKey,
     * tokenStore, locale, …) make the sApp partner client inside. Sharing: sapp: an existing partner client.
     */
    public static function customer(?string $appCode = null, ?PartnerClient $sapp = null, mixed ...$options): Customer
    {
        if ($sapp !== null) {
            if ($appCode !== null || $options !== []) {
                throw new \InvalidArgumentException('pass either sapp or the connection options, not both');
            }
            return new Customer($sapp);
        }
        if (!class_exists(PartnerClient::class)) {
            throw new \LogicException('the sLocker customer side needs superroute/sapp: composer require superroute/sapp');
        }
        if ($appCode === null || $appCode === '') {
            throw new \InvalidArgumentException('appCode is required');
        }
        return new Customer(new PartnerClient($appCode, ...$options));
    }
}
