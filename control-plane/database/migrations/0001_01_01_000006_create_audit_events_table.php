<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Decision 0013 and its amendment of 2026-10-08. `actor_id` and
        // `resource_id` are not foreign keys: an event outlives the account.
        Schema::create('audit_events', function (Blueprint $table): void {
            // UUIDv7 generated on insert, so the key is the order of writing.
            $table->uuid('id')->primary();
            $table->string('actor_type', 32);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('resource', 64);
            $table->uuid('resource_id')->nullable();
            $table->jsonb('metadata');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['actor_type', 'actor_id']);
            $table->index(['resource', 'resource_id']);
            $table->index('created_at');
        });

        // Append-only. This guards against bugs; it does not stop someone
        // who controls the database. The 12 months are a floor that is not
        // configurable: retention can be shortened to it, a bug cannot
        // remove recent history. OR REPLACE: `migrate:fresh` drops the
        // tables and leaves the function behind.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF (NEW.ip_address IS NULL OR NEW.ip_address IS NOT DISTINCT FROM OLD.ip_address)
                        AND (NEW.user_agent IS NULL OR NEW.user_agent IS NOT DISTINCT FROM OLD.user_agent)
                        AND (to_jsonb(NEW) - 'ip_address' - 'user_agent') = (to_jsonb(OLD) - 'ip_address' - 'user_agent')
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'audit_events is append-only: only ip_address and user_agent may be cleared'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF OLD.created_at < now() - interval '12 months' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'audit_events is append-only: an event younger than 12 months cannot be deleted'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'audit_events is append-only: it cannot be truncated'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only()');
    }
};
