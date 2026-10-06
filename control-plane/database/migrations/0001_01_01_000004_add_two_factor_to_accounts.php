<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const array TABLES = ['administrators', 'users'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // Encrypted by the model's cast.
                $table->text('two_factor_secret')->nullable();
                $table->timestampTz('two_factor_confirmed_at')->nullable();
                // The last accepted TOTP time step. A code counts only for
                // a later step (replay protection, in PostgreSQL on purpose).
                $table->unsignedBigInteger('two_factor_last_step')->nullable();
                // A JSON list of SHA-256 hashes.
                $table->jsonb('two_factor_recovery_codes')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_confirmed_at',
                    'two_factor_last_step',
                    'two_factor_recovery_codes',
                ]);
            });
        }
    }
};
