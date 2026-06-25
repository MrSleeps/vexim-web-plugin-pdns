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
        // Register DNS Provider Plugin with the Core's Discovery Service
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
     * Register this plugin with the DNS Core's discovery service
     * So it appears in the Filament dropdown, and register this
     * plugin's DomainForm extension (extra fields + save logic)
     * so dns-core can apply it without knowing this package exists.
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
                $discoveryService->registerPlugin(PowerDnsProvider::class, [
                    'enabled' => true,
                    'priority' => 10,
                    'version' => '1.0.0',
                    'extension_class' => DomainFormExtension::class,
                ]);

                // Register this plugin's DomainForm extension into the
                // shared registry, so dns-core can apply it generically
                // without hardcoding a reference to this package.
                $discoveryService->registerFormExtension(
                    fn () => DomainFormExtension::components(),
                    fn ($record, $data) => DomainFormExtension::onSave($record, $data),
                );

                Log::info('PowerDNS provider plugin registered successfully');
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

        // Also listen for DKIM generation events from main app (directly)
        // Listen for DKIM generation events from main app (direct fallback)
        if (class_exists(DkimKeyGenerated::class)) {
            Event::listen(
                DkimKeyGenerated::class,
                function ($event) {
                    Log::debug('VEximPdns received DkimKeyGenerated', [
                        'zone' => $event->zone,
                        'name' => $event->name,
                        'type' => $event->type,
                    ]);

                    // Find the DNS domain for this zone using ownerDomain relationship
                    $dnsDomain = DnsDomain::whereHas('ownerDomain', function ($query) use ($event) {
                        $query->where('domain', $event->zone);
                    })->first();

                    if (! $dnsDomain) {
                        Log::warning('No DNS domain found for DKIM event', [
                            'zone' => $event->zone,
                        ]);

                        return;
                    }

                    // Only handle if this domain uses PowerDNS
                    if ($dnsDomain->provider->type !== 'pdns') {
                        Log::debug('Domain does not use PowerDNS, skipping', [
                            'provider_type' => $dnsDomain->provider->type,
                        ]);

                        return;
                    }

                    // Create the DNS record directly - use the client which handles trailing dots
                    $client = new PowerDnsClient($dnsDomain->provider, $dnsDomain);

                    try {
                        // The client's createRecord method now handles trailing dots and TXT quoting
                        $result = $client->createRecord(
                            $event->zone,  // Zone without dot - client will add it
                            $event->name,   // Record name - client will normalize
                            $event->type,
                            $event->content, // Content - client will quote if TXT
                            $event->ttl
                        );

                        Log::info('PowerDNS record created via event', [
                            'zone' => $event->zone,
                            'name' => $event->name,
                            'result' => $result,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to create PowerDNS record: ' . $e->getMessage());
                    }
                }
            );
        }
    }
}
