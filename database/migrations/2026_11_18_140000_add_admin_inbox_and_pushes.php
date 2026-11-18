<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-Umbau (18.11.2026): eine Meldung lässt sich „lassen“ (die Freigabe
 * bleibt), und jede Push-Nachricht aus dem Admin steht im Verlauf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_reports', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('reason');
        });

        Schema::create('admin_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 20);          // all | group | user | test
            $table->string('audience_label', 80);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->string('url', 200)->default('/dashboard');
            $table->unsignedInteger('reached')->default(0);
            $table->unsignedInteger('via_telegram')->default(0);
            $table->unsignedInteger('via_push')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_pushes');
        Schema::table('workout_reports', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
