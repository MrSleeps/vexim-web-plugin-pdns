<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vw_pdns_sync_logs')) {
            Schema::create('vw_pdns_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('domain_id')->nullable();
                $table->string('action');        // create, update, delete
                $table->string('record_type');   // dkim, spf, dmarc, mx, etc.
                $table->string('record_name')->nullable();
                $table->text('request_data')->nullable();
                $table->text('response_data')->nullable();
                $table->string('status');         // success, failed, pending
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index('domain_id');
                $table->index('status');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vw_pdns_sync_logs');
    }
};
