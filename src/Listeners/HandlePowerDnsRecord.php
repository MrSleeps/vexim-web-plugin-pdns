<?php

namespace VEximweb\Plugin\PDNS\Listeners;

use Illuminate\Support\Facades\Log;
use VEximweb\Plugin\DnsCore\Events\DnsRecordRequired;
use VEximweb\Plugin\PDNS\Clients\PowerDnsClient;

class HandlePowerDnsRecord
{
    public function handle(DnsRecordRequired $event)
    {
        Log::debug('HandlePowerDnsRecord received event', [
            'zone' => $event->zone,
            'name' => $event->name,
            'type' => $event->type,
            'operation' => $event->operation,
            'domain_id' => $event->domain->id ?? null,
            'provider_type' => $event->domain->provider->type ?? null,
        ]);

        // Check if this domain uses PowerDNS
        if (!isset($event->domain->provider) || $event->domain->provider->type !== 'pdns') {
            Log::debug('Not a PowerDNS domain, skipping', [
                'provider_type' => $event->domain->provider->type ?? 'none'
            ]);
            return;
        }

        try {
            $client = new PowerDnsClient($event->domain->provider, $event->domain);

            Log::debug('PowerDNS client initialized', [
                'base_url' => $event->domain->provider->api_url,
                'server_id' => $event->domain->provider->settings['server_id'] ?? 'localhost',
                'enabled' => $client->isEnabled(),
            ]);

            switch ($event->operation) {
                case 'create':
                    Log::debug('Creating PowerDNS record', [
                        'zone' => $event->zone,
                        'name' => $event->name,
                        'type' => $event->type,
                        'content' => $event->content,
                        'ttl' => $event->ttl,
                    ]);

                    $result = $client->createRecord(
                        $event->zone,
                        $event->name,
                        $event->type,
                        $event->content,
                        $event->ttl
                    );

                    Log::info('PowerDNS record created successfully', [
                        'zone' => $event->zone,
                        'name' => $event->name,
                        'type' => $event->type,
                    ]);

                    return $result;

                case 'delete':
                    Log::debug('Deleting PowerDNS record', [
                        'zone' => $event->zone,
                        'recordId' => $event->recordId,
                    ]);

                    return $client->deleteRecord($event->zone, $event->recordId);

                default:
                    Log::warning('Unknown operation in HandlePowerDnsRecord', [
                        'operation' => $event->operation,
                    ]);
                    return false;
            }
        } catch (\Exception $e) {
            Log::error('HandlePowerDnsRecord failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'event_data' => [
                    'zone' => $event->zone,
                    'name' => $event->name,
                    'type' => $event->type,
                    'operation' => $event->operation,
                ],
            ]);

            throw $e;
        }
    }
}