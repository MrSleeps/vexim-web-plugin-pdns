<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vw_pdns_failed_operations')) {
            Schema::create('vw_pdns_failed_operations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('domain_id');
                $table->string('operation');      // create_zone, delete_zone, create_record, etc.
                $table->json('payload');
                $table->text('error_message');
                $table->integer('retry_count')->default(0);
                $table->timestamp('last_retry_at')->nullable();
                $table->timestamps();

                $table->index('domain_id');
                $table->index('retry_count');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vw_pdns_failed_operations');
    }
};
