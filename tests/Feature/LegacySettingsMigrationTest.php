<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('vw_dns_providers');
    Schema::dropIfExists('settings');

    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });

    Schema::create('vw_dns_providers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('type');
        $table->string('api_url')->nullable();
        $table->text('api_key')->nullable();
        $table->json('settings')->nullable();
        $table->boolean('is_default')->default(false);
        $table->boolean('is_enabled')->default(true);
        $table->integer('priority')->default(0);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('vw_dns_providers');
    Schema::dropIfExists('settings');
});

it('migrates legacy PowerDNS settings into a provider', function () {
    DB::table('settings')->insert([
        ['key' => 'pdns_enabled', 'value' => '1'],
        ['key' => 'pdns_api_url', 'value' => 'https://pdns.example.test'],
        ['key' => 'pdns_api_token', 'value' => 'legacy-token'],
    ]);

    $migration = require __DIR__ . '/../../database/migrations/2026_06_15_000001_migrate_legacy_pdns_settings.php';
    $migration->up();

    $provider = DB::table('vw_dns_providers')->where('type', 'pdns')->first();

    expect($provider)->not->toBeNull()
        ->and($provider->name)->toBe('PowerDNS (Migrated)')
        ->and($provider->api_url)->toBe('https://pdns.example.test')
        ->and($provider->api_key)->toBe('legacy-token')
        ->and((bool) $provider->is_default)->toBeTrue()
        ->and((bool) $provider->is_enabled)->toBeTrue();
});

it('does not overwrite an existing PowerDNS provider', function () {
    DB::table('settings')->insert([
        ['key' => 'pdns_api_url', 'value' => 'https://legacy.example.test'],
        ['key' => 'pdns_api_token', 'value' => 'legacy-token'],
    ]);

    DB::table('vw_dns_providers')->insert([
        'name' => 'Existing PowerDNS',
        'type' => 'pdns',
        'api_url' => 'https://current.example.test',
        'api_key' => 'current-token',
        'settings' => null,
        'is_default' => true,
        'is_enabled' => true,
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require __DIR__ . '/../../database/migrations/2026_06_15_000001_migrate_legacy_pdns_settings.php';
    $migration->up();

    expect(DB::table('vw_dns_providers')->where('type', 'pdns')->count())->toBe(1)
        ->and(DB::table('vw_dns_providers')->where('type', 'pdns')->value('api_url'))
        ->toBe('https://current.example.test');
});
