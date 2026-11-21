<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table) {
            $table->json('draft')->nullable()->after('theme');
            $table->unsignedInteger('version')->default(0)->after('draft');
        });

        Schema::create('theme_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('name', 60)->nullable();
            $table->json('theme');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['kind', 'created_at']);
            $table->index(['kind', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_snapshots');

        Schema::table('site_themes', function (Blueprint $table) {
            $table->dropColumn(['draft', 'version']);
        });
    }
};
