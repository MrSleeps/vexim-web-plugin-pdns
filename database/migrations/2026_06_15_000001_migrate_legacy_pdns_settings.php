<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only run if settings table exists and dns_providers table exists
        if (! Schema::hasTable('settings') || ! Schema::hasTable('vw_dns_providers')) {
            return;
        }

        // Check if there are any existing PowerDNS settings in the old table
        $pdnsEnabled = DB::table('settings')->where('key', 'pdns_enabled')->first();
        $pdnsApiUrl = DB::table('settings')->where('key', 'pdns_api_url')->first();
        $pdnsApiToken = DB::table('settings')->where('key', 'pdns_api_token')->first();

        // If no settings exist, skip
        if (! $pdnsApiUrl && ! $pdnsApiToken) {
            return;
        }

        // Check if a PowerDNS provider already exists in the new table
        $existingProvider = DB::table('vw_dns_providers')
            ->where('type', 'pdns')
            ->first();

        if (! $existingProvider) {
            // Migrate the settings to the new providers table
            DB::table('vw_dns_providers')->insert([
                'name' => 'PowerDNS (Migrated)',
                'type' => 'pdns',
                'api_url' => $pdnsApiUrl ? $pdnsApiUrl->value : '',
                'api_key' => $pdnsApiToken ? $pdnsApiToken->value : '',
                'is_default' => true,
                'is_enabled' => $pdnsEnabled ? (bool) $pdnsEnabled->value : false,
                'priority' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Don't delete migrated data on rollback
        // The user can delete it manually if needed
    }
};
