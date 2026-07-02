<?php

namespace VEximweb\Plugin\PDNS;

use App\Events\DkimKeyGenerated;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VEximweb\Plugin\DnsCore\DnsClientResolver;
use VEximweb\Plugin\DnsCore\Events\DnsRecordRequired;
use VEximweb\Plugin\DnsCore\Events\RegisterDnsClients;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Services\DnsProviderDiscoveryService;
use VEximweb\Plugin\PDNS\Clients\PowerDnsClient;
use VEximweb\Plugin\PDNS\Commands\VEximPdnsCommand;
use VEximweb\Plugin\PDNS\Filament\DomainFormExtension;
use VEximweb\Plugin\PDNS\Filament\DmarcFormExtension;
use VEximweb\Plugin\PDNS\Filament\SpfFormExtension;
use VEximweb\Plugin\PDNS\Listeners\HandlePowerDnsRecord;
use VEximweb\Plugin\PDNS\Providers\PowerDnsProvider;

class VEximPdnsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'vexim-pdns';

    public static string $viewNamespace = 'vexim-pdns';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('mrsleeps/vexim-pdns');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        // Register with DNS Client Resolver if it exists (legacy method)
        if (class_exists(DnsClientResolver::class)) {
            $this->app->booted(function () {
                $resolver = app(DnsClientResolver::class);
                $resolver->register('pdns', PowerDnsClient::class);
            });
        }

        // Register with the new event-based system
        if (class_exists(RegisterDnsClients::class)) {
            Event::listen(RegisterDnsClients::class, function ($event) {
                $event->factory->register('pdns', PowerDnsClient::class);
            });
        }
    }

    public function packageBooted(): void
    {
        // Register with DomainForm extension system (this is the only one we need)
        $this->registerDomainFormExtension();
        $this->registerDmarcFormExtension();
        $this->registerSpfFormExtension();

        // Register DNS Provider Plugin with the Core's Discovery Service
        // This is only needed for the provider dropdown options
        $this->registerDnsProviderPlugin();

        // Asset Registration
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        // Icon Registration
        FilamentIcon::register($this->getIcons());

        // Handle Stubs
        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/vexim-pdns/{$file->getFilename()}"),
                ], 'vexim-pdns-stubs');
            }
        }

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Register the DomainForm extension directly with the main app's DomainForm
     * This injects the DNS configuration fields and handles saving
     */
    protected function registerDomainFormExtension(): void
    {
        // Check if DomainForm exists
        if (!class_exists(\VEximweb\Core\Domain\Filament\Resources\Schemas\DomainForm::class)) {
            Log::debug('DomainForm not found, skipping extension registration');
            return;
        }

        try {
            // Register the extension with DomainForm using its extend() method
            \VEximweb\Core\Domain\Filament\Resources\Schemas\DomainForm::extend(
                // Components callback - returns the form fields
                function () {
                    Log::debug('DomainForm extension components called');
                    return DomainFormExtension::components();
                },
                // Save hook - called when domain is saved
                function ($record, array $data) {
                    Log::debug('DomainForm extension save hook called', [
                        'record_id' => $record?->domain_id,
                        'has_pdns_provider' => isset($data['pdns_provider_id']),
                        'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
                    ]);
                    DomainFormExtension::onSave($record, $data);
                }
            );

        } catch (\Exception $e) {
            Log::error('Failed to register PDNS DomainForm extension: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Register the DMARCForm extension directly 
     * This injects the DNS configuration fields and handles saving
     */
    protected function registerDmarcFormExtension(): void
    {
        Log::debug('Dmarc form starting');

        // Check if DomainForm exists
        if (!class_exists(\VEximweb\Plugin\DnsTools\Filament\Resources\Dmarc\Modals\GenerateDmarcForm::class)) {
            Log::debug('DmarcForm not found, skipping extension registration');
            return;
        }

        try {
            // Register the extension with DomainForm using its extend() method
            \VEximweb\Plugin\DnsTools\Filament\Resources\Dmarc\Modals\GenerateDmarcForm::extend(
                function ($domain = null) {
                    Log::debug('DmarcForm extension components called', [
                        'domain_id' => $domain?->id,
                        'domain' => $domain?->domain,
                    ]);

                    // Pass the domain to the components method
                    return DmarcFormExtension::components($domain);
                },
                // Save hook - called when domain is saved
                function ($record, array $data) {
                    Log::debug('DmarcForm extension save hook called', [
                        'record_id' => $record?->domain_id,
                        'has_pdns_provider' => isset($data['pdns_provider_id']),
                        'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
                    ]);
                    DmarcFormExtension::onSave($record, $data);
                }
            );
        } catch (\Exception $e) {
            Log::error('Failed to register PDNS DMARCform extension: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    } 
    
    /**
     * Register the DomainForm extension directly with the main app's DomainForm
     * This injects the DNS configuration fields and handles saving
     */
    protected function registerSpfFormExtension(): void
    {
        Log::debug('Spf form starting');

        // Check if DomainForm exists
        if (!class_exists(\VEximweb\Plugin\DnsTools\Filament\Resources\Spf\Pages\GenerateSpfPage::class)) {
            Log::debug('SpfForm not found, skipping extension registration');
            return;
        }

        try {
            // Register the extension with DomainForm using its extend() method
            \VEximweb\Plugin\DnsTools\Filament\Resources\Spf\Pages\GenerateSpfPage::extend(
                function ($domain = null) {
                    Log::debug('Spf extension components called', [
                        'domain_id' => $domain?->id,
                        'domain' => $domain?->domain,
                    ]);

                    // Pass the domain to the components method
                    return SpfFormExtension::components($domain);
                },
                // Save hook - called when domain is saved
                function ($record, array $data) {
                    Log::debug('Spf extension save hook called', [
                        'record_id' => $record?->domain_id,
                        'has_pdns_provider' => isset($data['pdns_provider_id']),
                        'pdns_provider_id' => $data['pdns_provider_id'] ?? null,
                    ]);
                    SpfFormExtension::onSave($record, $data);
                }
            );
        } catch (\Exception $e) {
            Log::error('Failed to register PDNS Spfform extension: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }     
    
    /**
     * Register this plugin with the DNS Core's discovery service
     * This registers the provider type so it appears in the dropdown
     * IMPORTANT: We do NOT register the form extension here anymore
     * to avoid duplication. Only the provider itself.
     */
    protected function registerDnsProviderPlugin(): void
    {
        // Check if the DNS Core discovery service exists
        if (! class_exists(DnsProviderDiscoveryService::class)) {
            return;
        }

        // Wait until the core is fully booted
        $this->app->booted(function () {
            try {
                $discoveryService = app(DnsProviderDiscoveryService::class);

                // Register PowerDNS as a provider plugin
                // This makes "PowerDNS" appear in the provider dropdown
                $discoveryService->registerPlugin(PowerDnsProvider::class, [
                    'enabled' => true,
                    'priority' => 10,
                    'version' => '1.0.0',
                    'extension_class' => DomainFormExtension::class,
                ]);

                // IMPORTANT: We DO NOT register the form extension here anymore
                // It's now registered directly with DomainForm::extend()
                // This prevents duplicate form fields

                Log::info('PowerDNS provider plugin registered with discovery service');
            } catch (\Exception $e) {
                Log::error('Failed to register PowerDNS plugin: ' . $e->getMessage());
            }
        });
    }

    protected function getAssetPackageName(): ?string
    {
        return 'mrsleeps/vexim-pdns';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [
            // AlpineComponent::make('vexim-pdns', __DIR__ . '/../resources/dist/components/vexim-pdns.js'),
            // Css::make('vexim-pdns-styles', __DIR__ . '/../resources/dist/vexim-pdns.css'),
            // Js::make('vexim-pdns-scripts', __DIR__ . '/../resources/dist/vexim-pdns.js'),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            VEximPdnsCommand::class,
        ];
    }

    /**
     * @return array<string>
     */
    protected function getIcons(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getRoutes(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            '2026_06_15_000001_migrate_legacy_pdns_settings',
        ];
    }

protected function registerEventListeners(): void
{
    // Listen for DNS record needed events from dns-core
    if (class_exists(DnsRecordRequired::class)) {
        Event::listen(
            DnsRecordRequired::class,
            HandlePowerDnsRecord::class
        );
    }
    
    // Listen for DKIM generation events
    if (class_exists(DkimKeyGenerated::class)) {
        Event::listen(
            DkimKeyGenerated::class,
            function ($event) {
                Log::debug('VEximPdns received DkimKeyGenerated', [
                    'zone' => $event->zone,
                    'name' => $event->name,
                    'type' => $event->type,
                ]);

                // Find the Domain by its domain name
                $domain = \VEximweb\Core\Data\Models\Domain::where('domain', $event->zone)->first();

                if (! $domain) {
                    Log::warning('No domain found for DKIM event', [
                        'zone' => $event->zone,
                    ]);
                    return;
                }

                // Find the DnsDomain associated with this Domain
                $dnsDomain = \VEximweb\Plugin\DnsCore\Models\DnsDomain::where('domain_id', (int) $domain->domain_id)->first();

                if (! $dnsDomain) {
                    Log::warning('No DNS domain found for DKIM event', [
                        'zone' => $event->zone,
                        'domain_id' => $domain->domain_id,
                    ]);
                    return;
                }

                Log::debug('Found DnsDomain', [
                    'dns_domain_id' => $dnsDomain->id,
                    'domain_id' => $dnsDomain->domain_id,
                    'provider_id' => $dnsDomain->provider_id,
                    'is_active' => $dnsDomain->is_active,
                ]);

                // Check if the DNS domain is active
                if (!$dnsDomain->is_active) {
                    Log::debug('DNS domain is inactive, skipping DKIM record creation', [
                        'zone' => $event->zone,
                        'domain_id' => $domain->domain_id,
                    ]);
                    return;
                }

                // Load the provider relationship if not already loaded
                if (!$dnsDomain->relationLoaded('provider')) {
                    Log::debug('Loading provider relationship');
                    $dnsDomain->load('provider');
                }

                // Check if the provider exists
                if (!$dnsDomain->provider) {
                    Log::warning('DNS domain has no provider', [
                        'zone' => $event->zone,
                        'dns_domain_id' => $dnsDomain->id,
                        'provider_id' => $dnsDomain->provider_id,
                    ]);

                    // Try to find the provider directly
                    $provider = \VEximweb\Plugin\DnsCore\Models\DnsProvider::find($dnsDomain->provider_id);
                    if ($provider) {
                        Log::debug('Found provider directly', [
                            'provider_id' => $provider->id,
                            'provider_type' => $provider->type,
                        ]);
                        $dnsDomain->setRelation('provider', $provider);
                    } else {
                        Log::warning('Provider not found in database', [
                            'provider_id' => $dnsDomain->provider_id,
                        ]);
                        return;
                    }
                }

                // Only handle if this domain uses PowerDNS
                if ($dnsDomain->provider->type !== 'pdns') {
                    Log::debug('Domain does not use PowerDNS, skipping', [
                        'provider_type' => $dnsDomain->provider->type,
                    ]);
                    return;
                }

                // Create the DNS record directly
                $client = new \VEximweb\Plugin\PDNS\Clients\PowerDnsClient($dnsDomain->provider, $dnsDomain);

                try {
                    $result = $client->createRecord(
                        $event->zone,
                        $event->name,
                        $event->type,
                        $event->content,
                        $event->ttl
                    );
                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('DKIM Record Generation Success')
                        ->body("The DKIM key was added to the dns")
                        ->persistent()
                        ->send();                        
                    
                    if (class_exists(\App\Events\DnsRecordCreated::class)) {
                        Event::dispatch(new \App\Events\DnsRecordCreated(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $event->name,
                            recordValue: $event->content,
                            provider: $dnsDomain->provider->name ?? 'PowerDNS',
                            message: "DKIM DNS record added to " . ($dnsDomain->provider->name ?? 'PowerDNS')
                        ));

                        Log::debug('Dispatched DnsRecordCreated event');
                    }                           

                    Log::info('PowerDNS record created via event', [
                        'zone' => $event->zone,
                        'name' => $event->name,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create PowerDNS record: ' . $e->getMessage(), [
                        'zone' => $event->zone,
                        'name' => $event->name,
                        'error' => $e->getTraceAsString(),
                    ]);
                    $error = $e->getMessage();
                    \Filament\Notifications\Notification::make()
                        ->danger()
                        ->title('DKIM Record Generation Failed')
                        ->body($error)
                        ->persistent()
                        ->send();                      
                    
                    if (class_exists(\App\Events\DnsRecordFailed::class)) {
                        Event::dispatch(new \App\Events\DnsRecordFailed(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $event->name,
                            errorMessage: $e->getMessage(),
                            provider: $dnsDomain->provider->name ?? 'PowerDNS',
                            message: "DKIM DNS failed to be added to " . ($dnsDomain->provider->name ?? 'PowerDNS')
                        ));

                        Log::debug('Dispatched DnsRecordFailed event');
                    }                        
                }
            }
        );
    }
    
if (class_exists(\App\Events\DmarcKeyGenerated::class)) {
    Event::listen(
        \App\Events\DmarcKeyGenerated::class,
        function ($event) {
            Log::debug('VEximPdns received DmarcKeyGenerated', [
                'zone' => $event->zone,
                'name' => $event->name,
                'type' => $event->type,
            ]);

            $domain = null;
            $dnsDomain = null;
            $error = null;

            try {
                // Find the Domain by its domain name
                $domain = \VEximweb\Core\Data\Models\Domain::where('domain', $event->zone)->first();

                if (! $domain) {
                    Log::warning('No domain found for DMARC event', [
                        'zone' => $event->zone,
                    ]);
                    return;
                }

                // Find the DnsDomain associated with this Domain
                $dnsDomain = \VEximweb\Plugin\DnsCore\Models\DnsDomain::where('domain_id', (int) $domain->domain_id)->first();

                if (! $dnsDomain) {
                    Log::warning('No DNS domain found for DMARC event', [
                        'zone' => $event->zone,
                        'domain_id' => $domain->domain_id,
                    ]);
                    return;
                }

                Log::debug('Found DnsDomain for DMARC', [
                    'dns_domain_id' => $dnsDomain->id,
                    'domain_id' => $dnsDomain->domain_id,
                    'provider_id' => $dnsDomain->provider_id,
                    'is_active' => $dnsDomain->is_active,
                ]);

                if (!$dnsDomain->is_active) {
                    Log::debug('DNS domain is inactive, skipping DMARC record creation');
                    return;
                }

                if (!$dnsDomain->relationLoaded('provider')) {
                    $dnsDomain->load('provider');
                }

                if (!$dnsDomain->provider) {
                    Log::warning('DNS domain has no provider for DMARC', [
                        'zone' => $event->zone,
                        'dns_domain_id' => $dnsDomain->id,
                    ]);
                    return;
                }

                if ($dnsDomain->provider->type !== 'pdns') {
                    Log::debug('Domain does not use PowerDNS for DMARC, skipping', [
                        'provider_type' => $dnsDomain->provider->type,
                    ]);
                    return;
                }

                // Fix: Handle empty name properly for root/apex records
                $recordName = empty($event->name) ? '' : $event->name;
                $fullRecordName = empty($event->name) 
                    ? $event->zone  // For root records, just use the zone
                    : $event->name . '.' . $event->zone;

                Log::debug('Creating PowerDNS DMARC record', [
                    'zone' => $event->zone,
                    'name' => $recordName,
                    'full_record_name' => $fullRecordName,
                    'content' => $event->content,
                    'type' => $event->type,
                    'ttl' => $event->ttl ?? 3600,
                ]);

                $client = new \VEximweb\Plugin\PDNS\Clients\PowerDnsClient($dnsDomain->provider, $dnsDomain);

                $result = $client->createRecord(
                    $event->zone,
                    $recordName,  // Pass empty string for root records
                    $event->type,
                    $event->content,
                    $event->ttl ?? 3600
                );

                Log::info('PowerDNS DMARC record created successfully', [
                    'zone' => $event->zone,
                    'name' => $recordName,
                    'result' => $result,
                ]);

                // Send Filament notification
                \Filament\Notifications\Notification::make()
                    ->success()

                    ->title('DMARC Record Generation Success')
                    ->body('The generated DMARC record was added to the DNS')
                    ->persistent()
                    ->send();

                // Build the provider name and message
                $providerName = $dnsDomain->provider->name ?? 'PowerDNS';
                $message = "DMARC DNS record was added to {$providerName}";
                $title = "DMARC Record Added";

                // Dispatch DnsRecordCreated event with explicit message
                if (class_exists(\App\Events\DnsRecordCreated::class)) {
                    try {
                        Event::dispatch(new \App\Events\DnsRecordCreated(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $fullRecordName,
                            recordValue: $event->content,
                            provider: $providerName,
                            message: $message
                        ));
                    } catch (\Exception $e) {
                        Log::warning('Failed to dispatch DnsRecordCreated event: ' . $e->getMessage());
                    }
                }

                // Dispatch notification for UI
                if (class_exists(\App\Notifications\DnsRecordCreatedNotification::class)) {
                    try {
                        // Create the event with explicit message
                        $dnsEvent = new \App\Events\DnsRecordCreated(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $fullRecordName,
                            recordValue: $event->content,
                            provider: $providerName,
                            message: $message
                        );
                        
                        // Create the notification with the event
                        $notification = new \App\Notifications\DnsRecordCreatedNotification($dnsEvent);
                        
                        \Illuminate\Support\Facades\Notification::route('database', 1)->notify($notification);
                        
                        Log::debug('DMARC notification sent successfully', [
                            'message' => $message,
                            'record_name' => $fullRecordName,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to send DMARC notification: ' . $e->getMessage());
                    }
                }

                return true;

            } catch (\Exception $e) {
                $error = $e->getMessage();
                
                Log::error('PowerDNS DMARC creation failed', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'zone' => $event->zone,
                    'name' => $event->name,
                ]);
                
                // Send Filament error notification
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('DMARC Record Generation Failed')
                    ->body($error)
                    ->persistent()
                    ->send();

                // Dispatch the failure event for notification
                if (class_exists(\App\Events\DnsRecordFailed::class) && $domain) {
                    try {
                        $recordName = empty($event->name) 
                            ? $event->zone 
                            : $event->name . '.' . $event->zone;
                            
                        Event::dispatch(new \App\Events\DnsRecordFailed(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $recordName,
                            errorMessage: 'PowerDNS: ' . $e->getMessage(),
                            provider: $dnsDomain->provider->name ?? 'PowerDNS'
                        ));
                    } catch (\Exception $notifyError) {
                        Log::warning('Failed to dispatch DnsRecordFailed event: ' . $notifyError->getMessage());
                    }
                }

                // DO NOT re-throw - this prevents the generic Livewire error page
                // Instead, flash the error to session for the UI to display
                if ($domain) {
                    session()->flash('error', 'DMARC Record Failed: ' . $e->getMessage());
                }

                // Return false to indicate failure
                return false;
            }
        }
    );
}
    
if (class_exists(\App\Events\SpfRecordGenerated::class)) {
    Event::listen(
        \App\Events\SpfRecordGenerated::class,
        function ($event) {
            Log::debug('VEximPdns received SpfRecordGenerated', [
                'zone' => $event->zone,
                'name' => $event->name,
                'type' => $event->type,
            ]);

            $domain = null;
            $dnsDomain = null;
            $error = null;

            try {
                // Find the Domain by its domain name
                $domain = \VEximweb\Core\Data\Models\Domain::where('domain', $event->zone)->first();

                if (! $domain) {
                    Log::warning('No domain found for SPF event', [
                        'zone' => $event->zone,
                    ]);
                    return;
                }

                // Find the DnsDomain associated with this Domain
                $dnsDomain = \VEximweb\Plugin\DnsCore\Models\DnsDomain::where('domain_id', (int) $domain->domain_id)->first();

                if (! $dnsDomain) {
                    Log::warning('No DNS domain found for SPF event', [
                        'zone' => $event->zone,
                        'domain_id' => $domain->domain_id,
                    ]);
                    return;
                }

                Log::debug('Found DnsDomain for SPF', [
                    'dns_domain_id' => $dnsDomain->id,
                    'domain_id' => $dnsDomain->domain_id,
                    'provider_id' => $dnsDomain->provider_id,
                    'is_active' => $dnsDomain->is_active,
                ]);

                if (!$dnsDomain->is_active) {
                    Log::debug('DNS domain is inactive, skipping SPF record creation');
                    return;
                }

                if (!$dnsDomain->relationLoaded('provider')) {
                    $dnsDomain->load('provider');
                }

                if (!$dnsDomain->provider) {
                    Log::warning('DNS domain has no provider for SPF', [
                        'zone' => $event->zone,
                        'dns_domain_id' => $dnsDomain->id,
                    ]);
                    return;
                }

                if ($dnsDomain->provider->type !== 'pdns') {
                    Log::debug('Domain does not use PowerDNS for SPF, skipping', [
                        'provider_type' => $dnsDomain->provider->type,
                    ]);
                    return;
                }

                // Fix: Handle empty name properly for root/apex records
                $recordName = empty($event->name) ? '' : $event->name;
                $fullRecordName = empty($event->name) 
                    ? $event->zone  // For root records, just use the zone
                    : $event->name . '.' . $event->zone;

                Log::debug('Creating PowerDNS SPF record', [
                    'zone' => $event->zone,
                    'name' => $recordName,
                    'full_record_name' => $fullRecordName,
                    'content' => $event->content,
                    'type' => $event->type,
                    'ttl' => $event->ttl ?? 3600,
                ]);

                $client = new \VEximweb\Plugin\PDNS\Clients\PowerDnsClient($dnsDomain->provider, $dnsDomain);

                $result = $client->createRecord(
                    $event->zone,
                    $recordName,  // Pass empty string for root records
                    $event->type,
                    $event->content,
                    $event->ttl ?? 3600
                );

                Log::info('PowerDNS SPF record created successfully', [
                    'zone' => $event->zone,
                    'name' => $recordName,
                    'result' => $result,
                ]);

                // Send Filament notification
                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title('SPF DNS Record Generation Success')
                    ->body('The generated SPF record was added to the DNS')
                    ->persistent()
                    ->send();

                // Build the provider name and message
                $providerName = $dnsDomain->provider->name ?? 'PowerDNS';
                $message = "SPF record was added to {$providerName}";
                $title = "SPF Record Added";

                // Dispatch DnsRecordCreated event with explicit message
                if (class_exists(\App\Events\DnsRecordCreated::class)) {
                    try {
                        Event::dispatch(new \App\Events\DnsRecordCreated(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $fullRecordName,
                            recordValue: $event->content,
                            provider: $providerName,
                            message: $message
                        ));
                    } catch (\Exception $e) {
                        Log::warning('Failed to dispatch DnsRecordCreated event: ' . $e->getMessage());
                    }
                }

                // Dispatch notification for UI
                if (class_exists(\App\Notifications\DnsRecordCreatedNotification::class)) {
                    try {
                        // Create the event with explicit message
                        $dnsEvent = new \App\Events\DnsRecordCreated(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $fullRecordName,
                            recordValue: $event->content,
                            provider: $providerName,
                            message: $message
                        );
                        
                        // Create the notification with the event
                        $notification = new \App\Notifications\DnsRecordCreatedNotification($dnsEvent);
                        
                        \Illuminate\Support\Facades\Notification::route('database', 1)->notify($notification);
                        
                        Log::debug('Notification sent successfully', [
                            'message' => $message,
                            'record_name' => $fullRecordName,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to send notification: ' . $e->getMessage());
                    }
                }

                return true;

            } catch (\Exception $e) {
                $error = $e->getMessage();
                
                Log::error('PowerDNS SPF creation failed', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'zone' => $event->zone,
                    'name' => $event->name,
                ]);
                
                // Send Filament error notification
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('SPF Record Generation Failed')
                    ->body($error)
                    ->persistent()
                    ->send();

                // Dispatch the failure event for notification
                if (class_exists(\App\Events\DnsRecordFailed::class) && $domain) {
                    try {
                        $recordName = empty($event->name) 
                            ? $event->zone 
                            : $event->name . '.' . $event->zone;
                            
                        Event::dispatch(new \App\Events\DnsRecordFailed(
                            domain: $domain,
                            recordType: $event->type,
                            recordName: $recordName,
                            errorMessage: 'PowerDNS: ' . $e->getMessage(),
                            provider: $dnsDomain->provider->name ?? 'PowerDNS'
                        ));
                    } catch (\Exception $notifyError) {
                        Log::warning('Failed to dispatch DnsRecordFailed event: ' . $notifyError->getMessage());
                    }
                }

                // DO NOT re-throw - this prevents the generic Livewire error page
                // Instead, flash the error to session for the UI to display
                if ($domain) {
                    session()->flash('error', 'SPF Record Failed: ' . $e->getMessage());
                }

                // Return false to indicate failure
                return false;
            }
        }
    );
} 
}
}