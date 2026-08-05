<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('task_templates', 'applies_to_all_collections')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->boolean('applies_to_all_collections')->default(false)->after('task_collection_id');
                $table->index('applies_to_all_collections', 'task_templates_all_collections_index');
            });
        }

        if (! Schema::hasColumn('weekly_task_templates', 'applies_to_all_collections')) {
            Schema::table('weekly_task_templates', function (Blueprint $table) {
                $table->boolean('applies_to_all_collections')->default(false)->after('task_collection_id');
                $table->index('applies_to_all_collections', 'weekly_task_templates_all_collections_index');
            });
        }

        if (! Schema::hasTable('task_template_task_collection')) {
            Schema::create('task_template_task_collection', function (Blueprint $table) {
                $table->unsignedBigInteger('task_template_id');
                $table->unsignedBigInteger('task_collection_id');

                $table->primary(['task_template_id', 'task_collection_id'], 'task_template_collection_primary');
                $table->foreign('task_template_id', 'tttc_template_fk')->references('id')->on('task_templates')->cascadeOnDelete();
                $table->foreign('task_collection_id', 'tttc_collection_fk')->references('id')->on('task_collections')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('weekly_task_template_task_collection')) {
            Schema::create('weekly_task_template_task_collection', function (Blueprint $table) {
                $table->unsignedBigInteger('weekly_task_template_id');
                $table->unsignedBigInteger('task_collection_id');

                $table->primary(['weekly_task_template_id', 'task_collection_id'], 'weekly_task_template_collection_primary');
                $table->foreign('weekly_task_template_id', 'wtttc_template_fk')->references('id')->on('weekly_task_templates')->cascadeOnDelete();
                $table->foreign('task_collection_id', 'wtttc_collection_fk')->references('id')->on('task_collections')->cascadeOnDelete();
            });
        }

        DB::table('task_templates')
            ->whereNotNull('task_collection_id')
            ->orderBy('id')
            ->get(['id', 'task_collection_id'])
            ->each(function ($row): void {
                DB::table('task_template_task_collection')->insertOrIgnore([
                    'task_template_id' => $row->id,
                    'task_collection_id' => $row->task_collection_id,
                ]);
            });

        DB::table('weekly_task_templates')
            ->whereNotNull('task_collection_id')
            ->orderBy('id')
            ->get(['id', 'task_collection_id'])
            ->each(function ($row): void {
                DB::table('weekly_task_template_task_collection')->insertOrIgnore([
                    'weekly_task_template_id' => $row->id,
                    'task_collection_id' => $row->task_collection_id,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_task_template_task_collection');
        Schema::dropIfExists('task_template_task_collection');

        if (Schema::hasColumn('weekly_task_templates', 'applies_to_all_collections')) {
            Schema::table('weekly_task_templates', function (Blueprint $table) {
                $table->dropIndex('weekly_task_templates_all_collections_index');
                $table->dropColumn('applies_to_all_collections');
            });
        }

        if (Schema::hasColumn('task_templates', 'applies_to_all_collections')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->dropIndex('task_templates_all_collections_index');
                $table->dropColumn('applies_to_all_collections');
            });
        }
    }
};
