<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('is_default');
        });

        Schema::create('task_collection_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_collection_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
            $table->index('task_collection_id');
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->foreignId('task_collection_id')->nullable()->after('task_session_id')->constrained()->restrictOnDelete();
            $table->index(['is_active', 'task_collection_id']);
        });

        Schema::table('weekly_task_templates', function (Blueprint $table) {
            $table->foreignId('task_collection_id')->nullable()->after('task_session_id')->constrained()->restrictOnDelete();
            $table->index(['is_active', 'task_collection_id']);
            $table->dropIndex('weekly_templates_active_repeat_index');
            $table->dropColumn('repeat_every_weeks');
        });

        $now = now('UTC');
        $defaultCollectionId = DB::table('task_collections')->insertGetId([
            'name' => 'General',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('task_templates')
            ->whereNull('task_collection_id')
            ->update(['task_collection_id' => $defaultCollectionId]);

        DB::table('weekly_task_templates')
            ->whereNull('task_collection_id')
            ->update(['task_collection_id' => $defaultCollectionId]);
    }

    public function down(): void
    {
        Schema::table('weekly_task_templates', function (Blueprint $table) {
            $table->unsignedTinyInteger('repeat_every_weeks')->default(1)->after('starts_on');
            $table->index(['is_active', 'repeat_every_weeks'], 'weekly_templates_active_repeat_index');
            $table->dropIndex(['is_active', 'task_collection_id']);
            $table->dropForeign(['task_collection_id']);
            $table->dropColumn('task_collection_id');
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'task_collection_id']);
            $table->dropForeign(['task_collection_id']);
            $table->dropColumn('task_collection_id');
        });

        Schema::dropIfExists('task_collection_schedules');
        Schema::dropIfExists('task_collections');
    }
};
