<?php

use VEximweb\Plugin\PDNS\Providers\PowerDnsProvider;

it('describes the PowerDNS provider consistently', function () {
    expect(PowerDnsProvider::getType())->toBe('pdns')
        ->and(PowerDnsProvider::getName())->toBe('PowerDNS')
        ->and(PowerDnsProvider::getDescription())->toContain('API v1')
        ->and(PowerDnsProvider::getIcon())->toBe('heroicon-o-server-stack')
        ->and(PowerDnsProvider::getColor())->toBe('primary');
});

it('uses a configured API URL or the documented default', function () {
    $provider = new PowerDnsProvider;

    expect($provider->getApiUrl([]))->toBe('http://localhost:8081/api/v1')
        ->and($provider->getApiUrl(['api_url' => 'https://dns.example.test']))
        ->toBe('https://dns.example.test');
});
