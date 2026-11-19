<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One row per client organization. Holds uuids and state only: never an ERPNext key,
     * secret, generated key or signing secret. No foreign keys, so nothing stock is locked.
     */
    public function up(): void
    {
        Schema::create('alvora_erpnext_connections', function (Blueprint $table) {
            $table->char('uuid', 36)->primary();
            $table->char('company_uuid', 36)->unique();
            $table->string('host', 255);
            $table->string('status', 16)->default('connecting');
            $table->string('run_kind', 8)->nullable();
            $table->char('run_uuid', 36)->nullable();
            $table->unsignedTinyInteger('step')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->char('api_credential_uuid', 36)->nullable();
            $table->char('webhook_endpoint_uuid', 36)->nullable();
            $table->char('pending_api_credential_uuid', 36)->nullable();
            $table->char('pending_webhook_endpoint_uuid', 36)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->unsignedInteger('last_test_ms')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alvora_erpnext_connections');
    }
};
