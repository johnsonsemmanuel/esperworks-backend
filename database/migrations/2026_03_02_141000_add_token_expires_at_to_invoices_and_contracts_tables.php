<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('signing_token');
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('signing_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('token_expires_at');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('token_expires_at');
        });
    }
};
