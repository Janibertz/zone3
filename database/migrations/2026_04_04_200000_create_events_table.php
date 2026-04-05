<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('event_date');
            $table->string('race_distance')->default('custom'); // 5km, 10km, half_marathon, marathon, custom
            $table->float('distance_km')->nullable();           // km value for custom distances
            $table->char('priority', 1)->default('B');          // A, B, C
            $table->unsignedTinyInteger('target_time_hours')->default(0);
            $table->unsignedTinyInteger('target_time_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
