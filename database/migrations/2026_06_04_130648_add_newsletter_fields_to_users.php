<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('newsletter_opt_in')->default(true)->after('email');
            $table->string('unsubscribe_token', 64)->nullable()->unique()->after('newsletter_opt_in');
        });

        // Generate unsubscribe tokens for all existing users
        \App\Models\User::whereNull('unsubscribe_token')->each(
            fn ($u) => $u->update(['unsubscribe_token' => Str::random(64)])
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['newsletter_opt_in', 'unsubscribe_token']);
        });
    }
};
