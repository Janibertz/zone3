<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('name');
            $table->text('bio')->nullable()->after('avatar');
            $table->string('location', 100)->nullable()->after('bio');
            $table->smallInteger('birth_year')->unsigned()->nullable()->after('location');
            $table->string('favorite_distance', 50)->nullable()->after('birth_year');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'bio', 'location', 'birth_year', 'favorite_distance']);
        });
    }
};
