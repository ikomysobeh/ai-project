<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upstream_accounts', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('error_count');
        });
    }

    public function down(): void
    {
        Schema::table('upstream_accounts', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
