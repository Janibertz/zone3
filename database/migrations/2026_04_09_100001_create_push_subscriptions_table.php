<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Bewusst VARCHAR und nicht TEXT: MySQL laesst auf einer
            // TEXT-Spalte keinen Index ohne Laengenangabe zu (Fehler 1170),
            // und weiter unten steht ein UNIQUE darauf. Das Anlegen der
            // Tabelle scheiterte damit auf jeder frischen MySQL-Datenbank.
            //
            // 512 Zeichen sind reichlich: Push-Endpunkte von Google, Mozilla
            // und Apple liegen bei 100 bis 250 Zeichen. Mehr ginge auch nicht
            // ohne Weiteres — bei utf8mb4 deckelt InnoDB den Index bei 3072
            // Byte, also 768 Zeichen.
            $table->string('endpoint', 512);
            $table->string('public_key');   // p256dh
            $table->string('auth_token');   // auth
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // One subscription per endpoint
            $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
