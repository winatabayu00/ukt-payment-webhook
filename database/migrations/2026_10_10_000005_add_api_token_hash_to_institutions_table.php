<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // SHA-256 hex of the per-institution API token (64 chars).
            // Nullable so pre-existing rows stay valid; invoice auth
            // rejects institutions without a token at request time.
            $table->string('api_token_hash', 64)->nullable()->unique()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('api_token_hash');
        });
    }
};
