<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // A small copy for the category circles, see ImageUploads::thumbnail().
            $table->string('thumb_path')->nullable()->after('image_path');
            // Main sections ("Chargers") group the detailed ones ("Yesido car chargers").
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->nullOnDelete();
        });

        // Other spellings customers type, one per line or comma separated.
        // Searched in every language, never shown.
        Schema::table('brands', function (Blueprint $table) {
            $table->text('aliases')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('aliases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('thumb_path');
        });
    }
};
