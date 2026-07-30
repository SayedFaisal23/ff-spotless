<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->string('completion_note', 500)->nullable()->after('completed_at');
        });

        Schema::table('weekly_task_occurrences', function (Blueprint $table) {
            $table->string('completion_note', 500)->nullable()->after('completed_on');
        });

        Schema::table('daily_task_evidence', function (Blueprint $table) {
            $table->timestamp('invalidated_at', 6)->nullable()->after('size_bytes');
            $table->string('invalidated_by', 100)->nullable()->after('invalidated_at');
            $table->string('invalidation_reason', 1000)->nullable()->after('invalidated_by');
            $table->index('invalidated_at');
        });

        Schema::table('weekly_task_evidence', function (Blueprint $table) {
            $table->timestamp('invalidated_at', 6)->nullable()->after('size_bytes');
            $table->string('invalidated_by', 100)->nullable()->after('invalidated_at');
            $table->string('invalidation_reason', 1000)->nullable()->after('invalidated_by');
            $table->index('invalidated_at');
        });

        Schema::create('task_reopen_audits', function (Blueprint $table) {
            $table->id();
            $table->string('task_type', 20);
            $table->unsignedBigInteger('task_id');
            $table->string('task_name');
            $table->string('session_name', 100);
            $table->date('task_date');
            $table->timestamp('previous_completed_at', 6)->nullable();
            $table->string('completion_note', 500)->nullable();
            $table->unsignedInteger('invalidated_evidence_count');
            $table->string('reason', 1000);
            $table->string('performed_by', 100);
            $table->timestamp('occurred_at', 6);

            $table->index(['task_type', 'task_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reopen_audits');

        Schema::table('daily_task_evidence', function (Blueprint $table) {
            $table->dropIndex(['invalidated_at']);
            $table->dropColumn(['invalidated_at', 'invalidated_by', 'invalidation_reason']);
        });

        Schema::table('weekly_task_evidence', function (Blueprint $table) {
            $table->dropIndex(['invalidated_at']);
            $table->dropColumn(['invalidated_at', 'invalidated_by', 'invalidation_reason']);
        });

        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->dropColumn('completion_note');
        });

        Schema::table('weekly_task_occurrences', function (Blueprint $table) {
            $table->dropColumn('completion_note');
        });
    }
};
