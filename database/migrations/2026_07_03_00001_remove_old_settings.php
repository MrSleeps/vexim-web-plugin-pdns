<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('vw_settings')
            ->whereIn('key', ['pdns_api_url', 'pdns_enabled', '', 'pdns_api_token'])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the deleted records
        $settings = [
            ['key' => 'pdns_api_url', 'value' => 'https://api.example.com'],
            ['key' => 'pdns_enabled', 'value' => ''],
            ['key' => 'pdns_api_token', 'value' => ''],
        ];

        foreach ($settings as $setting) {
            DB::table('vw_settings')->insert($setting);
        }
    }
};
