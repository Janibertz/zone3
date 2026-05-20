<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->enum('category', ['architecture', 'features', 'api', 'decisions']);
            $table->string('title');
            $table->longText('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('wiki_changelogs', function (Blueprint $table) {
            $table->id();
            $table->string('commit_sha', 40)->index();
            $table->string('branch')->default('main');
            $table->string('pusher_name')->nullable();
            $table->json('commits');          // [{id, message, author, timestamp}, ...]
            $table->json('files_changed');    // [filename, ...]
            $table->longText('ai_summary')->nullable();
            $table->timestamp('pushed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_changelogs');
        Schema::dropIfExists('wiki_pages');
    }
};
