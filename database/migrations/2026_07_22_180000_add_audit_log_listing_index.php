<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL must run a concurrent index outside a transaction so audit writes
     * can continue while the existing history is indexed.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';

        DB::statement(
            'CREATE INDEX'.$concurrently.' IF NOT EXISTS audit_logs_created_at_id_index '
            .'ON audit_logs (created_at DESC, id DESC)'
        );
    }

    public function down(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';

        DB::statement('DROP INDEX'.$concurrently.' IF EXISTS audit_logs_created_at_id_index');
    }
};
