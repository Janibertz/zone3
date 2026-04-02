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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('strava_id')->unique()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // e.g., Run, Bike, etc.
            $table->double('distance'); // in meters
            $table->integer('moving_time'); // in seconds
            $table->integer('elapsed_time'); // in seconds
            $table->double('total_elevation_gain')->nullable();
            $table->double('average_speed')->nullable(); // m/s
            $table->double('max_speed')->nullable(); // m/s
            $table->dateTime('start_date');
            $table->string('location_city')->nullable();
            $table->string('location_state')->nullable();
            $table->string('location_country')->nullable();
            $table->json('polyline')->nullable(); // Store the route polyline
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
