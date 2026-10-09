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
        // Spec section 28: one record feeds the apps, emails and invoices.
        // It is a single row with a fixed ID (App\Domain\Branding\BrandingRecord::ID),
        // created by the first change. The three image columns hold the
        // name of a file on the `branding` disk, never a name from a client.
        Schema::create('branding', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('product_name');
            $table->string('logo_light')->nullable();
            $table->string('logo_dark')->nullable();
            $table->string('favicon')->nullable();
            $table->string('primary_light', 7)->nullable();
            $table->string('primary_foreground_light', 7)->nullable();
            $table->string('primary_dark', 7)->nullable();
            $table->string('primary_foreground_dark', 7)->nullable();
            $table->text('support_url')->nullable();
            $table->text('terms_url')->nullable();
            $table->text('privacy_url')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branding');
    }
};
