<?php

declare(strict_types=1);

namespace Superroute\SLocker\Customer;

use Superroute\SApp\Operation;
use Superroute\SApp\OperationFile;
use Superroute\SApp\PartnerClient;
use Superroute\SLocker\Customer\Generated\LockerApi;

/**
 * The customer side of sLocker over one sApp partner client (superroute/sapp). $api has one method per smart-locker
 * operation, with the names they had in sApp 0.2.1 (platformAppPickups, platformAppCollectPickup, …) and the locker
 * resale operations; $sapp is the partner client underneath, which signs in and holds the session both share.
 */
final class Customer
{
    /** One method per smart-locker operation. */
    public readonly LockerApi $api;

    public function __construct(public readonly PartnerClient $sapp)
    {
        $this->api = new LockerApi(fn (Operation $operation, array $params, ?array $body, array $files): mixed => $this->sapp->callOperation($operation, $params, $body, $files));
    }

    /**
     * Calls an operation described by hand through the sApp pipeline (see PartnerClient::callOperation).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $body
     * @param list<OperationFile> $files
     */
    public function callOperation(Operation $operation, array $params = [], ?array $body = null, array $files = []): mixed
    {
        return $this->sapp->callOperation($operation, $params, $body, $files);
    }
}
