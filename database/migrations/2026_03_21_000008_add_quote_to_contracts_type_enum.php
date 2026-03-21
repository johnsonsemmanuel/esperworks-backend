<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires redefining the full enum list to add a new value
        DB::statement("ALTER TABLE contracts MODIFY COLUMN type ENUM('contract', 'proposal', 'quote') NOT NULL DEFAULT 'contract'");
    }

    public function down(): void
    {
        // Remove quote rows first to avoid constraint error on rollback
        DB::statement("UPDATE contracts SET type = 'contract' WHERE type = 'quote'");
        DB::statement("ALTER TABLE contracts MODIFY COLUMN type ENUM('contract', 'proposal') NOT NULL DEFAULT 'contract'");
    }
};
