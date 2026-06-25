<?php

namespace VEximweb\Plugin\PDNS\Providers;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use VEximweb\Plugin\DnsCore\Contracts\DnsProviderPlugin;

class PowerDnsProvider implements DnsProviderPlugin
{
    public static function getType(): string
    {
        return 'pdns';
    }

    public static function getName(): string
    {
        return 'PowerDNS';
    }

    public static function getDescription(): string
    {
        return 'PowerDNS Authoritative Server with API v1 support';
    }

    public static function getIcon(): string
    {
        return 'heroicon-o-server-stack';
    }

    public static function getSettingsSchema(): array
    {
        return [
            TextInput::make('api_url')
                ->label('PowerDNS API URL')
                ->required()
                ->url()
                ->placeholder('http://localhost:8081/api/v1')
                ->helperText('Full URL to PowerDNS API endpoint'),

            TextInput::make('api_key')
                ->label('API Key')->password()
                ->required()
                ->helperText('X-API-Key for authentication'),

            Select::make('api_version')
                ->label('API Version')
                ->options(['v1' => 'Version 1'])
                ->default('v1')
                ->disabled()
                ->dehydrateStateUsing(fn () => 'v1')
                ->helperText('Currently only API v1 is supported'),

            TextInput::make('server_id')
                ->label('Server ID')
                ->default('localhost')
                ->helperText('PowerDNS server identifier (usually "localhost")'),
            /*
            KeyValue::make('additional_options')
                ->label('Additional Options')
                ->keyLabel('Option')
                ->valueLabel('Value')
                ->helperText('Provider-specific options (optional)'),
*/
        ];
    }

    public function testConnection(array $settings): bool
    {
        try {
            $client = new Client([
                'verify' => false, // For self-signed certs
                'timeout' => 5,
            ]);

            $apiUrl = $settings['api_url'] ?? 'http://localhost:8081/api/v1';
            $apiKey = $settings['api_key'] ?? '';

            $response = $client->get($apiUrl . '/servers', [
                'headers' => [
                    'X-API-Key' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::error('PowerDNS connection test failed: ' . $e->getMessage());

            return false;
        }
    }

    public function getApiUrl(array $settings): string
    {
        return $settings['api_url'] ?? 'http://localhost:8081/api/v1';
    }

    public static function getColor(): string
    {
        return 'primary';
    }
}
