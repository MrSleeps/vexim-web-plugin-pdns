<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use VEximweb\Core\Data\Models\Domain;
use VEximweb\Plugin\DnsCore\Models\DnsDomain;
use VEximweb\Plugin\DnsCore\Models\DnsProvider;
use VEximweb\Plugin\DnsCore\Services\DnsAccessControl;
use VEximweb\Plugin\PDNS\Filament\DnsRecordFormAccess;
use VEximweb\Plugin\PDNS\Filament\DomainFormExtension;
use VEximweb\Plugin\PDNS\Tests\Support\TestUser;

function pdnsAccessUser(int $id, bool $systemAdmin = false, bool $domainAdmin = false): TestUser
{
    $user = new TestUser;
    $user->forceFill(['id' => $id]);
    $user->systemAdmin = $systemAdmin;
    $user->domainAdmin = $domainAdmin;

    return $user;
}

function pdnsSetPolicy(string $policy): void
{
    DB::table('vw_settings')->updateOrInsert(
        ['key' => 'domain_admin_dns_access'],
        [
            'value' => $policy,
            'type' => 'string',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    Cache::forget('settings.all');
}

function pdnsProvider(string $name, ?int $ownerId = null, bool $enabled = true): DnsProvider
{
    $id = DB::table('vw_dns_providers')->insertGetId([
        'owner_user_id' => $ownerId,
        'name' => $name,
        'type' => 'pdns',
        'api_url' => null,
        'api_key' => null,
        'settings' => null,
        'is_default' => false,
        'is_enabled' => $enabled,
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DnsProvider::query()->findOrFail($id);
}

function pdnsDomain(): Domain
{
    return Domain::query()->findOrFail(100);
}

function pdnsAssignDomainAdmin(int $userId): void
{
    DB::table('vw_domain_user')->insert([
        'user_id' => $userId,
        'domain_id' => 100,
        'role' => 'domain_admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function pdnsMapping(
    DnsProvider $provider,
    string $serviceControl,
    string $recordControl = DnsAccessControl::RECORD_SYSTEM_ONLY,
    bool $active = true,
    ?string $zoneId = 'example.test',
): DnsDomain {
    $id = DB::table('vw_dns_domains')->insertGetId([
        'domain_id' => 100,
        'provider_id' => $provider->id,
        'zone_id' => $zoneId,
        'settings' => null,
        'is_active' => $active,
        'service_control' => $serviceControl,
        'record_control' => $recordControl,
        'last_sync_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DnsDomain::query()->findOrFail($id);
}

beforeEach(function () {
    foreach ([
        'vw_dns_domains',
        'vw_dns_providers',
        'vw_domain_user',
        'vw_settings',
        'domains',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('domains', function (Blueprint $table) {
        $table->unsignedBigInteger('domain_id')->primary();
        $table->string('domain');
    });

    Schema::create('vw_domain_user', function (Blueprint $table) {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('domain_id');
        $table->string('role');
        $table->timestamps();
    });

    Schema::create('vw_settings', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->text('value')->nullable();
        $table->string('type')->default('string');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('vw_dns_providers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('owner_user_id')->nullable();
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

    Schema::create('vw_dns_domains', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('domain_id');
        $table->unsignedBigInteger('provider_id');
        $table->string('zone_id')->nullable();
        $table->json('settings')->nullable();
        $table->boolean('is_active')->default(true);
        $table->string('service_control')->default(DnsAccessControl::SERVICE_SYSTEM_ONLY);
        $table->string('record_control')->default(DnsAccessControl::RECORD_SYSTEM_ONLY);
        $table->timestamp('last_sync_at')->nullable();
        $table->timestamps();
    });

    DB::table('domains')->insert([
        'domain_id' => 100,
        'domain' => 'example.test',
    ]);

    Cache::forget('settings.all');
});

afterEach(function () {
    auth()->forgetGuards();
    Cache::forget('settings.all');

    foreach ([
        'vw_dns_domains',
        'vw_dns_providers',
        'vw_domain_user',
        'vw_settings',
        'domains',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

it('lets a system admin create an active mapping and choose domain-admin controls', function () {
    $provider = pdnsProvider('Global PowerDNS');
    auth()->setUser(pdnsAccessUser(1, systemAdmin: true));

    DomainFormExtension::onSave(pdnsDomain(), [
        'pdns_provider_id' => $provider->id,
        'pdns_service_control' => DnsAccessControl::SERVICE_TOGGLE,
        'pdns_record_control' => DnsAccessControl::RECORD_DOMAIN_ADMIN,
    ]);

    $mapping = DnsDomain::query()->where('domain_id', 100)->firstOrFail();

    expect($mapping->provider_id)->toBe($provider->id)
        ->and($mapping->is_active)->toBeTrue()
        ->and($mapping->service_control)->toBe(DnsAccessControl::SERVICE_TOGGLE)
        ->and($mapping->record_control)->toBe(DnsAccessControl::RECORD_DOMAIN_ADMIN);
});

it('keeps a system-managed domain unchanged when a domain admin submits DNS changes', function () {
    pdnsSetPolicy(DnsAccessControl::POLICY_GLOBAL_ONLY);
    pdnsAssignDomainAdmin(10);

    $current = pdnsProvider('Current');
    $other = pdnsProvider('Other');
    pdnsMapping($current, DnsAccessControl::SERVICE_SYSTEM_ONLY, active: true);
    auth()->setUser(pdnsAccessUser(10, domainAdmin: true));

    DomainFormExtension::onSave(pdnsDomain(), [
        'pdns_provider_id' => $other->id,
        'pdns_is_active' => false,
    ]);

    $mapping = DnsDomain::query()->where('domain_id', 100)->firstOrFail();

    expect($mapping->provider_id)->toBe($current->id)
        ->and($mapping->is_active)->toBeTrue()
        ->and($mapping->zone_id)->toBe('example.test');
});

it('allows toggle-only domains to change active state but not provider', function () {
    pdnsSetPolicy(DnsAccessControl::POLICY_GLOBAL_ONLY);
    pdnsAssignDomainAdmin(10);

    $current = pdnsProvider('Current');
    $other = pdnsProvider('Other');
    pdnsMapping($current, DnsAccessControl::SERVICE_TOGGLE, active: true);
    auth()->setUser(pdnsAccessUser(10, domainAdmin: true));

    DomainFormExtension::onSave(pdnsDomain(), [
        'pdns_provider_id' => $other->id,
        'pdns_is_active' => false,
    ]);

    $mapping = DnsDomain::query()->where('domain_id', 100)->firstOrFail();

    expect($mapping->provider_id)->toBe($current->id)
        ->and($mapping->is_active)->toBeFalse()
        ->and($mapping->zone_id)->toBe('example.test');
});

it('allows full-control domains to switch to an allowed provider and clears the old zone id', function () {
    pdnsSetPolicy(DnsAccessControl::POLICY_GLOBAL_ONLY);
    pdnsAssignDomainAdmin(10);

    $current = pdnsProvider('Current');
    $other = pdnsProvider('Other');
    pdnsMapping($current, DnsAccessControl::SERVICE_FULL, active: true);
    auth()->setUser(pdnsAccessUser(10, domainAdmin: true));

    DomainFormExtension::onSave(pdnsDomain(), [
        'pdns_provider_id' => $other->id,
        'pdns_is_active' => false,
    ]);

    $mapping = DnsDomain::query()->where('domain_id', 100)->firstOrFail();

    expect($mapping->provider_id)->toBe($other->id)
        ->and($mapping->is_active)->toBeFalse()
        ->and($mapping->zone_id)->toBeNull();
});

it('rejects switching a full-control domain to another admins private provider', function () {
    pdnsSetPolicy(DnsAccessControl::POLICY_GLOBAL_AND_OWN);
    pdnsAssignDomainAdmin(10);

    $current = pdnsProvider('Current');
    $otherAdminsProvider = pdnsProvider('Private', 20);
    pdnsMapping($current, DnsAccessControl::SERVICE_FULL);
    auth()->setUser(pdnsAccessUser(10, domainAdmin: true));

    expect(fn () => DomainFormExtension::onSave(pdnsDomain(), [
        'pdns_provider_id' => $otherAdminsProvider->id,
        'pdns_is_active' => true,
    ]))->toThrow(ValidationException::class);
});

it('enforces record-control permissions for domain admins', function () {
    pdnsSetPolicy(DnsAccessControl::POLICY_GLOBAL_ONLY);
    pdnsAssignDomainAdmin(10);

    $provider = pdnsProvider('Global');
    $mapping = pdnsMapping(
        $provider,
        DnsAccessControl::SERVICE_SYSTEM_ONLY,
        DnsAccessControl::RECORD_SYSTEM_ONLY,
    );
    auth()->setUser(pdnsAccessUser(10, domainAdmin: true));

    expect(DnsRecordFormAccess::canUpdate(pdnsDomain()))->toBeFalse();

    $mapping->update(['record_control' => DnsAccessControl::RECORD_DOMAIN_ADMIN]);

    expect(DnsRecordFormAccess::canUpdate(pdnsDomain()))->toBeTrue();

    pdnsSetPolicy(DnsAccessControl::POLICY_DISABLED);

    expect(DnsRecordFormAccess::canUpdate(pdnsDomain()))->toBeFalse();
});
