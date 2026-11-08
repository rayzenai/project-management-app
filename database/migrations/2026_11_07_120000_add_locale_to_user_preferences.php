<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language each person reads the app in (English or Nepali,
 * App\Support\Locales); null until they choose, so the sign-in page's
 * choice (its cookie) still counts for someone who saved only a theme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->string('locale', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
