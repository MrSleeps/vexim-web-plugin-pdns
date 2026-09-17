<?php

namespace VEximweb\Plugin\PDNS\Filament;

use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\User;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Services\DnsAccessControl;

final class DnsRecordFormAccess
{
    public static function canUpdate(mixed $domainRecord): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $domainId = data_get($domainRecord, 'domain_id') ?? data_get($domainRecord, 'id');
        if (! is_numeric($domainId)) {
            return false;
        }

        $domainId = (int) $domainId;
        $domain = Domain::find($domainId);
        $dnsDomain = DnsDomain::where('domain_id', $domainId)->first();

        return $domain instanceof Domain
            && DnsAccessControl::canEditRecords($user, $domain, $dnsDomain);
    }
}
