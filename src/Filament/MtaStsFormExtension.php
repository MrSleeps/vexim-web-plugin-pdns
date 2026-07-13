<?php

namespace VEximweb\Plugin\PDNS\Filament;

use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsTools\Models\SystemDomains as Domain;
use Illuminate\Support\Facades\Log;

class MtaStsFormExtension
{
    public static function components($domainRecord = null): array
    {
        Log::debug('MtaStsFormExtension::components called', [
            'domain_id' => $domainRecord?->domain_id,
            'domain' => $domainRecord?->domain,
            'has_domain' => $domainRecord ? 'yes' : 'no',
        ]);

        return [
            Section::make('Power DNS')
                ->schema([
                    Toggle::make('update_dns')
                        ->label('Update DNS')
                        ->default(false)
                        ->afterStateHydrated(function ($component, $state) use ($domainRecord) {
                            if (!$domainRecord) {
                                Log::debug('No domain record, state remains false');
                                return;
                            }

                            $exists = DnsDomain::where('domain_id', $domainRecord->domain_id)->exists();

                            Log::debug('Setting toggle state', [
                                'domain_id' => $domainRecord->domain_id,
                                'domain' => $domainRecord->domain,
                                'old_state' => $state,
                                'new_state' => $exists,
                            ]);

                            // This should force the toggle to the correct state
                            $component->state($exists);
                        }),
                ])
                ->columns(2),
        ];
    }

    public static function onSave(mixed $record, array $data): void
    {
        Log::debug('MtaStsFormExtension::onSave called', [
            'domain_id' => $record?->id ?? $record?->domain_id ?? null,
            'domain' => $record?->domain ?? null,
            'update_dns' => $data['update_dns'] ?? null,
            'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
        ]);

        // If you want to actually save/delete the DNS domain record
        if ($record && isset($data['update_dns'])) {
            $domainId = $record->id ?? $record->domain_id ?? null;
            
            if (!$domainId) {
                Log::warning('No domain ID found for DNS save');
                return;
            }

        }
    }
}