<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Coach bekommt einen zweiten Kanal.
 *
 * `telegram_chat_id` ist eine Telegram-ID und kann jenseits von 2^31 liegen,
 * deshalb bigInteger. `telegram_link_token` ist der Einmal-Code aus dem
 * Profil; er verfaellt, sobald der Bot ihn eingeloest hat.
 *
 * Auf `coach_messages` haelt `channel` fest, wo eine Nachricht entstanden
 * ist (`web`, `telegram`, NULL = vom System, etwa ein Review), und
 * `reply_to_id`, auf welche Frage eine Antwort gehoert — der Web-Chat fragt
 * genau danach, seit die Antwort in der Queue entsteht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->nullable()->unique()->after('garmin_session');
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_link_token');
        });

        Schema::table('coach_messages', function (Blueprint $table) {
            $table->string('channel', 16)->nullable()->after('content');
            $table->foreignId('reply_to_id')->nullable()->after('channel')
                ->constrained('coach_messages')->nullOnDelete();
            $table->json('actions')->nullable()->after('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('coach_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn(['channel', 'actions']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropUnique(['telegram_link_token']);
            $table->dropColumn(['telegram_chat_id', 'telegram_link_token', 'telegram_linked_at']);
        });
    }
};
