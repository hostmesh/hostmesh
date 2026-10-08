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
        // Decision 0012: which administrator holds which role. The roles
        // themselves are code (App\Domain\Authorization\Role), so `role` is
        // a name, not a foreign key.
        Schema::create('administrator_roles', function (Blueprint $table): void {
            $table->foreignUuid('administrator_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->primary(['administrator_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('administrator_roles');
    }
};
