<?php

namespace VEximweb\Plugin\PDNS\Commands;

use Illuminate\Console\Command;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use Illuminate\Support\Facades\DB;

class SyncDomainsToDnsProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dns:sync-domains
                            {--provider= : The ID of the DNS provider to use (must be type pdns)}
                            {--force : Force overwrite existing DNS domain entries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all domains to DNS provider (only providers of type pdns)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get all pdns providers
        $providers = DnsProvider::where('type', 'pdns')
            ->where('is_enabled', true)
            ->orderBy('priority', 'desc')
            ->get();

        if ($providers->isEmpty()) {
            $this->error('No enabled DNS providers of type "pdns" found.');
            return 1;
        }

        // Select provider
        $provider = $this->selectProvider($providers);

        if (!$provider) {
            $this->error('No provider selected.');
            return 1;
        }

        $this->info("Selected provider: {$provider->name} (ID: {$provider->id})");

        // Get all domains
        $domains = Domain::all();
        $this->info("Found {$domains->count()} domains to process.");

        if ($domains->isEmpty()) {
            $this->info('No domains found to sync.');
            return 0;
        }

        // Confirm with user
        if (!$this->confirm("Do you want to sync all {$domains->count()} domains to '{$provider->name}'?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $this->syncDomains($domains, $provider);

        $this->info('Domain sync completed successfully!');
        return 0;
    }

    /**
     * Select a DNS provider from the list.
     *
     * @param \Illuminate\Database\Eloquent\Collection $providers
     * @return \VEximweb\Plugin\DnsCore\Models\DnsProvider|null
     */
    protected function selectProvider($providers)
    {
        // If provider ID is specified via option
        if ($this->option('provider')) {
            $provider = $providers->firstWhere('id', (int) $this->option('provider'));
            if ($provider) {
                return $provider;
            }
            $this->warn("Provider with ID {$this->option('provider')} not found or not enabled.");
        }

        // If only one provider exists, use it
        if ($providers->count() === 1) {
            $provider = $providers->first();
            $this->info("Only one provider available, automatically selected: {$provider->name}");
            return $provider;
        }

        // Let user choose from list
        $choices = $providers->map(function ($provider) {
            return "{$provider->name} (ID: {$provider->id}) - Priority: {$provider->priority}" .
                   ($provider->is_default ? ' [DEFAULT]' : '');
        })->toArray();

        $choice = $this->choice(
            'Select a DNS provider to use:',
            $choices,
            $providers->where('is_default', true)->first() ? 
                $providers->where('is_default', true)->keys()->first() : 
                0
        );

        // Extract provider ID from choice string
        preg_match('/\(ID: (\d+)\)/', $choice, $matches);
        $providerId = $matches[1] ?? null;

        return $providers->firstWhere('id', (int) $providerId);
    }

    /**
     * Sync domains to the DNS provider.
     *
     * @param \Illuminate\Database\Eloquent\Collection $domains
     * @param \VEximweb\Plugin\DnsCore\Models\DnsProvider $provider
     */
    protected function syncDomains($domains, $provider)
    {
        $bar = $this->output->createProgressBar($domains->count());
        $bar->start();

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            foreach ($domains as $domain) {
                try {
                    // Check if domain already exists in DnsDomain
                    $existing = DnsDomain::where('domain_id', $domain->domain_id)->first();

                    if ($existing) {
                        if ($this->option('force')) {
                            // Update existing record
                            $existing->update([
                                'provider_id' => $provider->id,
                                'zone_id' => $domain->domain,
                                'is_active' => true,
                            ]);
                            $synced++;
                            $this->line("\nUpdated: {$domain->domain}");
                        } else {
                            $skipped++;
                            $this->line("\nSkipped: {$domain->domain} (already exists, use --force to overwrite)");
                        }
                    } else {
                        // Create new record
                        DnsDomain::create([
                            'domain_id' => $domain->domain_id,
                            'provider_id' => $provider->id,
                            'zone_id' => $domain->domain,
                            'settings' => null,
                            'is_active' => true,
                            'last_sync_at' => now(),
                        ]);
                        $synced++;
                        $this->line("\nAdded: {$domain->domain}");
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $this->error("\nFailed to process domain: {$domain->domain} - {$e->getMessage()}");
                }

                $bar->advance();
            }

            DB::commit();

            $bar->finish();
            $this->newLine(2);

            // Summary
            $this->info('Summary:');
            $this->info("  ✅ Synced: {$synced}");
            $this->info("  ⏭️ Skipped: {$skipped}");
            $this->info("  ❌ Failed: {$failed}");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('An error occurred while syncing domains: ' . $e->getMessage());
            throw $e;
        }
    }
}