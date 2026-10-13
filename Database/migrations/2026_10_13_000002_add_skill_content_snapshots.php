<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Runtime Skill content is authoritative in the database; legacy references are not backfilled implicitly. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_skill_definitions')) {
            return;
        }
        if (! Schema::hasColumn('ai_skill_definitions', 'content_body')) {
            Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->longText('content_body')->nullable());
        }
        if (! Schema::hasColumn('ai_skill_definitions', 'content_sha256')) {
            Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->string('content_sha256', 64)->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_skill_definitions')) {
            return;
        }
        foreach (['content_body', 'content_sha256'] as $column) {
            if (Schema::hasColumn('ai_skill_definitions', $column)) {
                Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
