<?php

namespace VEximweb\Plugin\PDNS\Listeners;

use VEximweb\Plugin\DnsCore\Events\DnsRecordRequired;
use VEximweb\Plugin\PDNS\Clients\PowerDnsClient;

class HandlePowerDnsRecord
{
    public function handle(DnsRecordRequired $event)
    {
        // Check if this domain uses PowerDNS
        if ($event->domain->provider->type !== 'pdns') {
            return; // Not for us
        }

        $client = new PowerDnsClient($event->domain->provider, $event->domain);

        switch ($event->operation) {
            case 'create':
                return $client->createRecord(
                    $event->zone,
                    $event->name,
                    $event->type,
                    $event->content,
                    $event->ttl
                );
            case 'delete':
                return $client->deleteRecord($event->zone, $event->recordId);
            default:
                return false;
        }
    }
}
