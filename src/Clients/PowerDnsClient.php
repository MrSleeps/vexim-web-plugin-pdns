<?php

namespace VEximweb\Plugin\PDNS\Clients;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VEximweb\Plugin\DnsCore\Contracts\DnsClient;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;

class PowerDnsClient implements DnsClient
{
    protected DnsProvider $provider;

    protected ?DnsDomain $domain;

    protected string $baseUrl;

    protected string $apiKey;

    protected string $serverId;

    protected bool $enabled;

    public function __construct(DnsProvider $provider, ?DnsDomain $domain = null)
    {
        $this->provider = $provider;
        $this->domain = $domain;
        $this->baseUrl = rtrim($provider->api_url ?? '', '/');

        // Decrypt the API key if it's encrypted
        $this->apiKey = $this->decryptApiKey($provider->api_key ?? '');

        $this->serverId = $provider->settings['server_id'] ?? 'localhost';
        $this->enabled = $provider->is_enabled && ! empty($this->baseUrl) && ! empty($this->apiKey);
    }

    /**
     * Decrypt API key if it's encrypted
     */
    protected function decryptApiKey(string $apiKey): string
    {
        // Check if the key looks like encrypted (Laravel encrypted strings start with 'eyJ')
        if (str_starts_with($apiKey, 'eyJ')) {
            try {
                return Crypt::decryptString($apiKey);
            } catch (\Exception $e) {
                try {
                    return Crypt::decrypt($apiKey);
                } catch (\Exception $e) {
                    Log::error('Failed to decrypt API key: ' . $e->getMessage());

                    return '';
                }
            }
        }

        // Return as-is if not encrypted (for backward compatibility)
        return $apiKey;
    }

    /**
     * Normalize zone name by adding trailing dot (RFC format)
     */
    protected function normalizeZone(string $zone): string
    {
        $zone = trim($zone);

        return rtrim($zone, '.') . '.';
    }

    /**
     * Normalize record name by adding trailing dot if needed
     */
    protected function normalizeName(string $name, string $zone): string
    {
        $name = trim($name);

        // If it's a fully qualified domain name (ends with the zone name), add trailing dot
        if (str_ends_with($name, rtrim($zone, '.'))) {
            return rtrim($name, '.') . '.';
        }

        // If it doesn't have a dot at all, append zone
        if (! str_contains($name, '.')) {
            return $name . '.' . rtrim($zone, '.') . '.';
        }

        // Otherwise, just add trailing dot if missing
        return rtrim($name, '.') . '.';
    }

    /**
     * Format content based on record type
     */
    protected function formatContent(string $type, string $content, ?int $priority = null): string
    {
        // For TXT records, ensure content is quoted
        if ($type === 'TXT') {
            // Remove existing quotes if any
            $content = trim($content, '"');

            return '"' . $content . '"';
        }

        // For MX and SRV records, add priority
        if ($priority !== null && in_array($type, ['MX', 'SRV'])) {
            return "{$priority} {$content}";
        }

        return $content;
    }

    protected function request(string $method, string $endpoint, array $data = [], array $query = [])
    {
        if (! $this->enabled) {
            throw new \Exception('PowerDNS client is not enabled or configured');
        }

        $url = $this->baseUrl . '/api/v1/servers/' . $this->serverId . $endpoint;

        $response = Http::timeout(30)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->$method($url, $method === 'get' ? $query : $data);

        if ($response->status() === 401) {
            throw new \Exception('Invalid PowerDNS API key');
        }

        // 204 No Content is successful
        if ($response->status() === 204) {
            return true;
        }

        if (! $response->successful()) {
            throw new \Exception('PowerDNS API error: ' . $response->body());
        }

        return $response->json();
    }

    public function zoneExists(string $zone): bool
    {
        try {
            $normalizedZone = $this->normalizeZone($zone);
            $this->request('get', "/zones/{$normalizedZone}");

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createZone(string $zone, array $options = []): bool
    {
        $normalizedZone = $this->normalizeZone($zone);

        // Normalize nameservers with trailing dots
        $nameservers = $options['nameservers'] ?? ['ns1.example.com.', 'ns2.example.com.'];
        $nameservers = array_map(function ($ns) {
            return rtrim($ns, '.') . '.';
        }, $nameservers);

        $data = [
            'name' => $normalizedZone,
            'kind' => $options['kind'] ?? 'Master',
            'nameservers' => $nameservers,
        ];

        // Add optional SOA-EDIT if provided
        if (isset($options['soa_edit'])) {
            $data['soa_edit'] = $options['soa_edit'];
        }

        // Add master IPs for slave zones
        if (isset($options['masters'])) {
            $data['masters'] = $options['masters'];
        }

        $this->request('post', '/zones', $data);

        return true;
    }

    public function deleteZone(string $zone): bool
    {
        $normalizedZone = $this->normalizeZone($zone);
        $this->request('delete', "/zones/{$normalizedZone}");

        return true;
    }

    public function getZones(): array
    {
        $zones = $this->request('get', '/zones');

        // Transform to a simpler format if needed
        return array_map(function ($zone) {
            return [
                'id' => $zone['id'],
                'name' => $zone['name'],
                'type' => $zone['kind'] ?? 'Master',
                'records' => $zone['records'] ?? [],
                'serial' => $zone['serial'] ?? null,
            ];
        }, $zones);
    }

    public function getRecords(string $zone): array
    {
        $normalizedZone = $this->normalizeZone($zone);
        $zoneData = $this->request('get', "/zones/{$normalizedZone}");

        $records = [];
        foreach ($zoneData['rrsets'] ?? [] as $rrset) {
            foreach ($rrset['records'] ?? [] as $record) {
                // Clean content (remove quotes for display)
                $content = $record['content'];
                if ($rrset['type'] === 'TXT') {
                    $content = trim($content, '"');
                }

                $records[] = [
                    'id' => md5($rrset['name'] . $rrset['type'] . $record['content']),
                    'name' => rtrim($rrset['name'], '.'), // Remove trailing dot for display
                    'type' => $rrset['type'],
                    'content' => $content,
                    'ttl' => $rrset['ttl'] ?? 3600,
                    'disabled' => $record['disabled'] ?? false,
                    'priority' => $this->extractPriority($rrset['type'], $record['content']),
                ];
            }
        }

        return $records;
    }

    protected function extractPriority(string $type, string $content): ?int
    {
        // For MX and SRV records, priority is part of the content
        if (in_array($type, ['MX', 'SRV'])) {
            $parts = explode(' ', $content);

            // $parts[0] always exists, just check if it's numeric
            return is_numeric($parts[0]) ? (int) $parts[0] : null;
        }

        return null;
    }

    public function createRecord(string $zone, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): bool
    {
        $normalizedZone = $this->normalizeZone($zone);
        $normalizedName = $this->normalizeName($name, $zone);
        $formattedContent = $this->formatContent($type, $content, $priority);

        $data = [
            'rrsets' => [
                [
                    'name' => $normalizedName,
                    'type' => $type,
                    'ttl' => $ttl,
                    'records' => [
                        [
                            'content' => $formattedContent,
                            'disabled' => false,
                        ],
                    ],
                    'changetype' => 'REPLACE',
                ],
            ],
        ];

        $this->request('patch', "/zones/{$normalizedZone}", $data);

        return true;
    }

    public function deleteRecord(string $zone, string $recordId): bool
    {
        // Get the record details first
        $records = $this->getRecords($zone);
        $record = collect($records)->firstWhere('id', $recordId);

        if (! $record) {
            return false;
        }

        $normalizedZone = $this->normalizeZone($zone);
        $normalizedName = $this->normalizeName($record['name'], $zone);

        $data = [
            'rrsets' => [
                [
                    'name' => $normalizedName,
                    'type' => $record['type'],
                    'records' => [],
                    'changetype' => 'DELETE',
                ],
            ],
        ];

        $this->request('patch', "/zones/{$normalizedZone}", $data);

        return true;
    }

    public function testConnection(): bool
    {
        try {
            // Try to get servers list as a connection test
            $url = $this->baseUrl . '/api/v1/servers';

            $response = Http::timeout(10)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if ($response->status() === 401) {
                Log::error('PowerDNS connection test failed: Invalid API key');

                return false;
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('PowerDNS connection test failed: ' . $e->getMessage());

            return false;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Additional helper method to get zone details
     */
    public function getZone(string $zone): ?array
    {
        try {
            $normalizedZone = $this->normalizeZone($zone);

            return $this->request('get', "/zones/{$normalizedZone}");
        } catch (\Exception $e) {
            Log::error('Failed to get zone: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Update an existing record
     */
    public function updateRecord(string $zone, string $recordId, array $updates): bool
    {
        $records = $this->getRecords($zone);
        $record = collect($records)->firstWhere('id', $recordId);

        if (! $record) {
            return false;
        }

        // Delete the old record first
        $this->deleteRecord($zone, $recordId);

        // Create the updated record
        return $this->createRecord(
            $zone,
            $updates['name'] ?? $record['name'],
            $updates['type'] ?? $record['type'],
            $updates['content'] ?? $record['content'],
            $updates['ttl'] ?? $record['ttl'],
            $updates['priority'] ?? null
        );
    }
}
