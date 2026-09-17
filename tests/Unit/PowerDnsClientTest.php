<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;
use VEximweb\Plugin\PDNS\Clients\PowerDnsClient;

function makePowerDnsClientProvider(array $overrides = []): DnsProvider
{
    return new DnsProvider(array_merge([
        'name' => 'Test PowerDNS',
        'type' => 'pdns',
        'api_url' => 'https://pdns.test',
        'api_key' => 'test-api-key',
        'settings' => ['server_id' => 'test-server'],
        'is_default' => false,
        'is_enabled' => true,
        'priority' => 0,
    ], $overrides));
}

it('checks zone existence using the normalized PowerDNS URL and API key', function () {
    Http::fake([
        'https://pdns.test/api/v1/servers/test-server/zones/example.com.' => Http::response(['name' => 'example.com.'], 200),
    ]);

    $client = new PowerDnsClient(makePowerDnsClientProvider());

    expect($client->zoneExists(' example.com '))->toBeTrue();

    Http::assertSent(
        fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pdns.test/api/v1/servers/test-server/zones/example.com.'
        && $request->hasHeader('X-API-Key', 'test-api-key')
    );
});

it('writes normalized MX records with priority and TTL', function () {
    Http::fake([
        '*' => Http::response(null, 204),
    ]);

    $client = new PowerDnsClient(makePowerDnsClientProvider());

    expect($client->createRecord(
        'example.com',
        'mail',
        'MX',
        'mx.example.net.',
        7200,
        10,
    ))->toBeTrue();

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->method() === 'PATCH'
            && $request->url() === 'https://pdns.test/api/v1/servers/test-server/zones/example.com.'
            && data_get($data, 'rrsets.0.name') === 'mail.example.com.'
            && data_get($data, 'rrsets.0.type') === 'MX'
            && data_get($data, 'rrsets.0.ttl') === 7200
            && data_get($data, 'rrsets.0.records.0.content') === '10 mx.example.net.'
            && data_get($data, 'rrsets.0.changetype') === 'REPLACE';
    });
});

it('parses TXT and MX records returned by PowerDNS', function () {
    Http::fake([
        'https://pdns.test/api/v1/servers/test-server/zones/example.com.' => Http::response([
            'rrsets' => [
                [
                    'name' => 'example.com.',
                    'type' => 'TXT',
                    'ttl' => 300,
                    'records' => [
                        ['content' => '"v=spf1 -all"', 'disabled' => false],
                    ],
                ],
                [
                    'name' => 'example.com.',
                    'type' => 'MX',
                    'ttl' => 3600,
                    'records' => [
                        ['content' => '20 mail.example.net.', 'disabled' => false],
                    ],
                ],
            ],
        ], 200),
    ]);

    $records = (new PowerDnsClient(makePowerDnsClientProvider()))->getRecords('example.com');

    expect($records)->toHaveCount(2)
        ->and($records[0]['name'])->toBe('example.com')
        ->and($records[0]['type'])->toBe('TXT')
        ->and($records[0]['content'])->toBe('v=spf1 -all')
        ->and($records[0]['ttl'])->toBe(300)
        ->and($records[0]['priority'])->toBeNull()
        ->and($records[1]['type'])->toBe('MX')
        ->and($records[1]['content'])->toBe('20 mail.example.net.')
        ->and($records[1]['priority'])->toBe(20);
});

it('refuses API requests when the provider is disabled', function () {
    $client = new PowerDnsClient(makePowerDnsClientProvider([
        'is_enabled' => false,
    ]));

    expect(fn () => $client->createZone('example.com'))
        ->toThrow(Exception::class, 'PowerDNS client is not enabled or configured');
});

it('tests the PowerDNS connection with the configured API key', function () {
    Http::fake([
        'https://pdns.test/api/v1/servers' => Http::response([], 200),
    ]);

    $client = new PowerDnsClient(makePowerDnsClientProvider());

    expect($client->testConnection())->toBeTrue();

    Http::assertSent(
        fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pdns.test/api/v1/servers'
        && $request->hasHeader('X-API-Key', 'test-api-key')
    );
});

it('reports a failed connection for an invalid API key', function () {
    Http::fake([
        'https://pdns.test/api/v1/servers' => Http::response([], 401),
    ]);

    $client = new PowerDnsClient(makePowerDnsClientProvider());

    expect($client->testConnection())->toBeFalse();
});
