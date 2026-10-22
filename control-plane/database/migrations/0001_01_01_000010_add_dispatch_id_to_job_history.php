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
        // Decision 0014, amendment of 2026-10-22 (issue #155). A pushed job
        // carries the token of its dispatch, and only the copy whose token
        // the row holds may run it. The sweep gives a row a new token and a
        // new job, so an old copy that Redis still holds does nothing. Rows
        // from before get a token of their own; the application writes it
        // from then on, so no default stays behind.
        DB::statement('ALTER TABLE job_history ADD COLUMN dispatch_id uuid NOT NULL DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE job_history ALTER COLUMN dispatch_id DROP DEFAULT');

        // The sweep looks for unfinished rows that nobody touched for a
        // while. Finished rows are nearly all of the table and stay for
        // good. Its condition must stay the one in
        // App\Application\Services\JobHistorySweeper, or PostgreSQL cannot
        // use it.
        DB::statement(
            "CREATE INDEX job_history_unfinished_updated_at_index ON job_history (updated_at)
                WHERE status IN ('pending', 'running', 'waiting', 'retrying')",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS job_history_unfinished_updated_at_index');
        DB::statement('ALTER TABLE job_history DROP COLUMN IF EXISTS dispatch_id');
    }
};
