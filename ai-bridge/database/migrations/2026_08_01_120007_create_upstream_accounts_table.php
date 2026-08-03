<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upstream_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // per-user pool, no shared fallback
            $table->string('label');
            $table->text('cookies_encrypted');
            $table->string('status')->default('active'); // active|cooling_down|expired
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upstream_accounts');
    }
};
