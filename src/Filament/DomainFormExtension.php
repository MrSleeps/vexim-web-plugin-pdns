<?php

namespace VEximweb\Plugin\PDNS\Filament;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;
use Illuminate\Support\Facades\Log;

class DomainFormExtension
{
    protected static function existingRow(mixed $record): ?DnsDomain
    {
        if (! $record?->exists) {
            return null;
        }

        static $cache = [];
        $key = $record->domain_id;

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = DnsDomain::where('domain_id', $key)->first();
        }

        return $cache[$key];
    }

    public static function components(): array
    {
        return [
            Section::make('DNS Configuration')
                ->schema([
                    Select::make('pdns_provider_id')
                        ->label('DNS Provider')
                        ->options(fn () => DnsProvider::pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->helperText('Leave blank to disable DNS management')
                        ->dehydrated(true)
                        ->afterStateHydrated(function ($component, $record) {
                            $row = self::existingRow($record);
                            if ($row) {
                                $component->state($row->provider_id);
                            }
                        }),

                    Toggle::make('pdns_is_active')
                        ->label('DNS Active')
                        ->default(true)
                        ->visible(fn (Get $get) => filled($get('pdns_provider_id')))
                        ->dehydrated(true)
                        ->afterStateHydrated(function ($component, $record) {
                            $row = self::existingRow($record);
                            if ($row) {
                                $component->state($row->is_active);
                            }
                        }),
                ])
                ->columns(2),
        ];
    }

    public static function onSave(mixed $record, array $data): void
    {
        Log::debug('DomainFormExtension::onSave called', [
            'record_id' => $record?->domain_id,
            'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
            'pdns_is_active' => $data['pdns_is_active'] ?? null,
        ]);

        $providerId = $data['pdns_provider_id'] ?? null;

        // If no provider is selected, delete the DNS domain record
        if (blank($providerId)) {
            Log::debug('No provider selected, deleting DNS domain record', [
                'domain_id' => $record->domain_id,
            ]);
            
            DnsDomain::where('domain_id', $record->domain_id)->delete();
            return;
        }

        // If a provider is selected, update or create the record
        Log::debug('Saving DNS configuration', [
            'domain_id' => $record->domain_id,
            'provider_id' => $providerId,
            'is_active' => $data['pdns_is_active'] ?? true,
        ]);

        DnsDomain::updateOrCreate(
            ['domain_id' => $record->domain_id],
            [
                'provider_id' => $providerId,
                'zone_id' => null, // falls back to domain name via getDomainNameAttribute()
                'is_active' => $data['pdns_is_active'] ?? true,
            ]
        );

        Log::debug('DNS configuration saved successfully');
    }
}