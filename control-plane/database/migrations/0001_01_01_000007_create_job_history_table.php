<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

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
        // Spec section 6 and decision 0014: what was attempted, for which
        // generation, on which node, with what result. One row per
        // operation. `resource_id` and `node_id` are not foreign keys: a
        // row outlives the resource and the node.
        Schema::create('job_history', function (Blueprint $table): void {
            // UUIDv7 generated on insert, so the key is the order of writing.
            $table->uuid('id')->primary();
            $table->string('type', 64);
            $table->string('resource_type', 64);
            $table->uuid('resource_id');
            $table->unsignedBigInteger('generation')->nullable();
            $table->uuid('node_id')->nullable();
            $table->string('status', 16);
            $table->string('failure_class', 16)->nullable();
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['resource_type', 'resource_id']);
            $table->index('node_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_history');
    }
};
