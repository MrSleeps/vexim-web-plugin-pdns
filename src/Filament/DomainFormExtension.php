<?php

namespace VEximweb\Plugin\PDNS\Filament;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Core\Data\Models\User;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;
use VEximweb\Plugin\DnsCore\Services\DnsAccessControl;

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

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function canChangeProvider(mixed $record): bool
    {
        $user = self::currentUser();

        if ($user?->isSystemAdmin()) {
            return true;
        }

        return $record instanceof Domain
            && DnsAccessControl::canChangeProvider($user, $record, self::existingRow($record));
    }

    protected static function canToggleService(mixed $record): bool
    {
        $user = self::currentUser();

        if ($user?->isSystemAdmin()) {
            return true;
        }

        return $record instanceof Domain
            && DnsAccessControl::canToggleService($user, $record, self::existingRow($record));
    }

    protected static function providerOptions(mixed $record): array
    {
        $user = self::currentUser();
        $options = DnsAccessControl::selectableProviders($user)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $row = self::existingRow($record);
        if ($row && ! array_key_exists($row->provider_id, $options)) {
            $provider = DnsProvider::find($row->provider_id);
            if ($provider) {
                $options[$provider->id] = $provider->name;
            }
        }

        return $options;
    }

    public static function components(): array
    {
        return [
            Section::make('DNS Configuration')
                ->schema([
                    Select::make('pdns_provider_id')
                        ->label('DNS Provider')
                        ->options(fn ($record) => self::providerOptions($record))
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->disabled(fn ($record) => ! self::canChangeProvider($record))
                        ->required(fn ($record) => self::currentUser()?->isDomainAdmin() && self::canChangeProvider($record))
                        ->helperText(function ($record) {
                            if (self::currentUser()?->isSystemAdmin()) {
                                return 'Leave blank to remove DNS management from this domain.';
                            }

                            return self::canChangeProvider($record)
                                ? 'Choose an enabled global provider or one of your own providers.'
                                : 'The DNS provider for this domain is managed by the system administrator.';
                        })
                        ->dehydrated(true)
                        ->afterStateUpdated(function ($state, Set $set, $record) {
                            if (! self::canChangeProvider($record)) {
                                return;
                            }

                            if (blank($state)) {
                                $set('pdns_is_active', false);

                                return;
                            }

                            if (self::existingRow($record) === null) {
                                $set('pdns_is_active', true);
                            }
                        })
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
                        ->disabled(fn ($record) => ! self::canToggleService($record))
                        ->helperText(fn ($record) => self::canToggleService($record)
                            ? 'Enable or disable DNS management for this domain.'
                            : 'DNS activation is controlled by the system administrator.')
                        ->dehydrated(true)
                        ->afterStateHydrated(function ($component, $record) {
                            $row = self::existingRow($record);
                            if ($row) {
                                $component->state($row->is_active);
                            }
                        }),

                    Select::make('pdns_service_control')
                        ->label('Domain Admin Service Control')
                        ->options([
                            DnsAccessControl::SERVICE_SYSTEM_ONLY => 'System only',
                            DnsAccessControl::SERVICE_TOGGLE => 'May enable / disable',
                            DnsAccessControl::SERVICE_FULL => 'May choose provider and enable / disable',
                        ])
                        ->default(DnsAccessControl::SERVICE_SYSTEM_ONLY)
                        ->visible(fn () => self::currentUser()?->isSystemAdmin() ?? false)
                        ->dehydrated(true)
                        ->afterStateHydrated(function ($component, $record) {
                            $row = self::existingRow($record);
                            if ($row) {
                                $component->state($row->service_control);
                            }
                        }),

                    Select::make('pdns_record_control')
                        ->label('Domain Admin Record Control')
                        ->options([
                            DnsAccessControl::RECORD_SYSTEM_ONLY => 'System only',
                            DnsAccessControl::RECORD_DOMAIN_ADMIN => 'Domain admin may edit records',
                        ])
                        ->default(DnsAccessControl::RECORD_SYSTEM_ONLY)
                        ->visible(fn () => self::currentUser()?->isSystemAdmin() ?? false)
                        ->dehydrated(true)
                        ->afterStateHydrated(function ($component, $record) {
                            $row = self::existingRow($record);
                            if ($row) {
                                $component->state($row->record_control);
                            }
                        }),
                ])
                ->columns(2),
        ];
    }

    public static function onSave(mixed $record, array $data): void
    {
        if (! $record instanceof Domain) {
            return;
        }

        $user = self::currentUser();
        $existing = DnsDomain::where('domain_id', $record->domain_id)->first();

        Log::debug('DomainFormExtension::onSave called', [
            'record_id' => $record->domain_id,
            'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
            'pdns_is_active' => $data['pdns_is_active'] ?? null,
        ]);

        if (! $user) {
            return;
        }

        if (! $user->isSystemAdmin()) {
            if (! $existing || ! DnsAccessControl::canAdministerDomain($user, $record)) {
                return;
            }

            $providerId = $existing->provider_id;
            $isActive = $existing->is_active;

            if (DnsAccessControl::canChangeProvider($user, $record, $existing)) {
                $submittedProviderId = $data['pdns_provider_id'] ?? null;
                if (blank($submittedProviderId)) {
                    throw ValidationException::withMessages([
                        'pdns_provider_id' => 'Choose a DNS provider. Use DNS Active to disable DNS for this domain.',
                    ]);
                }

                $provider = DnsProvider::find($submittedProviderId);
                if (! $provider || ((int) $provider->id !== (int) $existing->provider_id && ! DnsAccessControl::canUseProvider($user, $provider))) {
                    throw ValidationException::withMessages([
                        'pdns_provider_id' => 'You are not allowed to use that DNS provider.',
                    ]);
                }

                $providerId = $provider->id;
            }

            if (DnsAccessControl::canToggleService($user, $record, $existing)) {
                $isActive = (bool) ($data['pdns_is_active'] ?? false);
            }

            $existing->update([
                'provider_id' => $providerId,
                'zone_id' => (int) $providerId === (int) $existing->provider_id ? $existing->zone_id : null,
                'is_active' => $isActive,
            ]);

            return;
        }

        $providerId = $data['pdns_provider_id'] ?? null;

        if (blank($providerId)) {
            DnsDomain::where('domain_id', $record->domain_id)->delete();

            return;
        }

        $provider = DnsProvider::find($providerId);
        if (! $provider) {
            throw ValidationException::withMessages([
                'pdns_provider_id' => 'The selected DNS provider no longer exists.',
            ]);
        }

        if (! $provider->is_enabled && (int) $existing?->provider_id !== (int) $provider->id) {
            throw ValidationException::withMessages([
                'pdns_provider_id' => 'The selected DNS provider is disabled.',
            ]);
        }

        $serviceControl = $data['pdns_service_control'] ?? DnsAccessControl::SERVICE_SYSTEM_ONLY;
        if (! in_array($serviceControl, [
            DnsAccessControl::SERVICE_SYSTEM_ONLY,
            DnsAccessControl::SERVICE_TOGGLE,
            DnsAccessControl::SERVICE_FULL,
        ], true)) {
            $serviceControl = DnsAccessControl::SERVICE_SYSTEM_ONLY;
        }

        $recordControl = $data['pdns_record_control'] ?? DnsAccessControl::RECORD_SYSTEM_ONLY;
        if (! in_array($recordControl, [
            DnsAccessControl::RECORD_SYSTEM_ONLY,
            DnsAccessControl::RECORD_DOMAIN_ADMIN,
        ], true)) {
            $recordControl = DnsAccessControl::RECORD_SYSTEM_ONLY;
        }

        DnsDomain::updateOrCreate(
            ['domain_id' => $record->domain_id],
            [
                'provider_id' => $provider->id,
                'zone_id' => (int) $existing?->provider_id === (int) $provider->id ? $existing?->zone_id : null,
                'is_active' => (bool) ($data['pdns_is_active'] ?? true),
                'service_control' => $serviceControl,
                'record_control' => $recordControl,
            ]
        );

        Log::debug('DNS configuration saved successfully');
    }
}
