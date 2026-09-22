<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retail vendors.
 *
 * One row per commercial channel through which the house's fixed ticket
 * paper passes to the street. The GLO reality: vendors are staffed
 * licensed dealers carrying exactly as much printed stock as their channel
 * can safely move; the row vendors the two numbers that guard the lane —
 * its lifecycle state (RetailVendorStatus) and its paper HOLDING CAPACITY
 * (quota_capacity), from which overscheduling is eliminated at the source.
 *
 * vendor_code uniqueness is the anti-replay surface: manufacturing the
 * same vendor twice answers with the same row, not a forked twin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_vendors', function (Blueprint $table): void {
            $table->id();

            // Canonical machine reference, quoted everywhere: 'GLO-BKK-001'.
            $table->string('vendor_code', 64)->unique();

            $table->string('name', 255);
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 64)->nullable();

            // RetailVendorStatus: pending / active / suspended / closed.
            $table->string('status', 32)->default('pending')->index();

            // Maximum paper the channel may hold simultaneously, in units.
            $table->unsignedInteger('quota_capacity')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_vendors');
    }
};
