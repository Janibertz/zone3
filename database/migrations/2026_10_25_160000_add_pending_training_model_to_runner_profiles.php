<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Modellwechsel wirkt ab dem nächsten Wochenplan, nicht sofort
 * (Entscheidung vom 25.10.2026). Bis dahin steht das neue Modell hier,
 * mit dem Montag, ab dem es gilt — eine laufende Woche wird nicht
 * umgeworfen, nur weil jemand am Mittwoch im Profil etwas umstellt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->string('training_model_next', 20)->nullable()->after('training_model');
            $table->date('training_model_next_from')->nullable()->after('training_model_next');
        });
    }

    public function down(): void
    {
        Schema::table('runner_profiles', function (Blueprint $table) {
            $table->dropColumn(['training_model_next', 'training_model_next_from']);
        });
    }
};
