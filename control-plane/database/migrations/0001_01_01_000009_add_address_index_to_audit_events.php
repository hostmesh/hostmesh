<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Decision 0013, amendment of 2026-10-22 (issue #238). The job that
        // clears addresses looks for expired events that still hold one.
        // Through the index on created_at alone, every batch read past all
        // the events cleared before it, and so did every daily run, through
        // months of them. This index holds only the events that still have
        // an address: about the last 90 days. Its condition must stay the
        // one in App\Application\Services\AuditRetention, or PostgreSQL
        // cannot use it.
        DB::statement(
            'CREATE INDEX audit_events_addresses_created_at_index ON audit_events (created_at)
                WHERE ip_address IS NOT NULL OR user_agent IS NOT NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_events_addresses_created_at_index');
    }
};
