<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Einladungslinks für mehrere (11.11.2026, Jan: „einen öffentlichen
 * Einladungslink … der für mehrere gültig ist“).
 *
 * `max_uses` 1 = wie bisher, eine Zahl = so viele Konten, null = beliebig
 * viele. `source` landet als `signup_source` am Konto, damit man sieht,
 * was der Link gebracht hat. Wer über welchen Link kam, steht an
 * `users.invitation_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->unsignedInteger('max_uses')->nullable()->default(1)->after('note');
            $table->unsignedInteger('uses_count')->default(0)->after('max_uses');
            $table->string('source', 40)->nullable()->after('uses_count');
        });

        DB::table('invitations')->whereNotNull('used_at')->update(['uses_count' => 1]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('invitations')->whereNotNull('used_by')->orderBy('id')->each(function ($i) {
            DB::table('users')->where('id', $i->used_by)->update(['invitation_id' => $i->id]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitation_id');
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['max_uses', 'uses_count', 'source']);
        });
    }
};
