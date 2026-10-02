<?php

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_type')->nullable();
            $table->string('business_location_lat')->nullable();
            $table->string('business_location_lng')->nullable();
            $table->text('business_address')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'business_type',
                'business_location_lat',
                'business_location_lng',
                'business_address'
            ]);
        });
    }
};
