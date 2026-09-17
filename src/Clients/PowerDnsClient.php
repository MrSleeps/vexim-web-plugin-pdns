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
        $this->apiKey = $this->decryptApiKey($provider->api_key ?? '');
        $this->serverId = $provider->settings['server_id'] ?? 'localhost';
        $this->enabled = $provider->is_enabled && ! empty($this->baseUrl) && ! empty($this->apiKey);
    }

    protected function decryptApiKey(string $apiKey): string
    {
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

        return $apiKey;
    }

    protected function normalizeZone(string $zone): string
    {
        $zone = trim($zone);

        return rtrim($zone, '.') . '.';
    }

    protected function effectiveZone(string $zone): string
    {
        if (! $this->domain) {
            return rtrim($zone, '.');
        }

        if (method_exists($this->domain, 'authoritativeZoneName')) {
            return $this->domain->authoritativeZoneName();
        }

        return rtrim((string) ($this->domain->zone_id ?: $zone), '.');
    }

    protected function qualifyRecordName(string $name): string
    {
        if (! $this->domain) {
            return $name;
        }

        $domainName = rtrim($this->domain->domain_name, '.');
        $name = rtrim(trim($name), '.');

        if ($name === '' || $name === '@') {
            return $domainName;
        }

        $normalizedName = strtolower($name);
        $normalizedDomain = strtolower($domainName);

        if ($normalizedName === $normalizedDomain || str_ends_with($normalizedName, '.' . $normalizedDomain)) {
            return $name;
        }

        return $name . '.' . $domainName;
    }

    protected function normalizeName(string $name, string $zone): string
    {
        $name = rtrim(trim($name), '.');
        $zone = rtrim($zone, '.');

        if ($name === '') {
            return $zone . '.';
        }

        if ($name === $zone || str_ends_with($name, '.' . $zone)) {
            return $name . '.';
        }

        if (! str_contains($name, '.')) {
            return $name . '.' . $zone . '.';
        }

        return $name . '.';
    }

    protected function formatContent(string $type, string $content, ?int $priority = null): string
    {
        if ($type === 'TXT') {
            $content = trim($content, '"');

            return '"' . $content . '"';
        }

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

        $nameservers = $options['nameservers'] ?? ['ns1.example.com.', 'ns2.example.com.'];
        $nameservers = array_map(function ($ns) {
            return rtrim($ns, '.') . '.';
        }, $nameservers);

        $data = [
            'name' => $normalizedZone,
            'kind' => $options['kind'] ?? 'Master',
            'nameservers' => $nameservers,
        ];

        if (isset($options['soa_edit'])) {
            $data['soa_edit'] = $options['soa_edit'];
        }

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
                $content = $record['content'];
                if ($rrset['type'] === 'TXT') {
                    $content = trim($content, '"');
                }

                $records[] = [
                    'id' => md5($rrset['name'] . $rrset['type'] . $record['content']),
                    'name' => rtrim($rrset['name'], '.'),
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
        if (in_array($type, ['MX', 'SRV'])) {
            $parts = explode(' ', $content);

            return is_numeric($parts[0]) ? (int) $parts[0] : null;
        }

        return null;
    }

    public function createRecord(string $zone, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): bool
    {
        $zone = $this->effectiveZone($zone);
        $name = $this->qualifyRecordName($name);

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
        $zone = $this->effectiveZone($zone);
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

    public function updateRecord(string $zone, string $recordId, array $updates): bool
    {
        $zone = $this->effectiveZone($zone);
        $records = $this->getRecords($zone);
        $record = collect($records)->firstWhere('id', $recordId);

        if (! $record) {
            return false;
        }

        $this->deleteRecord($zone, $recordId);

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
