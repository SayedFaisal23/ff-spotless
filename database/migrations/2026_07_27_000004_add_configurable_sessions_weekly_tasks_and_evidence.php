<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index('sort_order');
            $table->index(['is_active', 'sort_order']);
        });

        $now = CarbonImmutable::now('UTC');
        DB::table('task_sessions')->insert([
            ['name' => 'Pagi', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Tengah Hari', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Petang', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $sessionIds = DB::table('task_sessions')->pluck('id', 'name');

        Schema::table('task_templates', function (Blueprint $table) {
            $table->foreignId('task_session_id')->nullable()->after('task_name')->constrained('task_sessions')->restrictOnDelete();
            $table->decimal('credit_hours', 6, 2)->default(1)->after('session');
            $table->unsignedInteger('sort_order')->default(0)->after('credit_hours');
        });

        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->foreignId('task_session_id')->nullable()->after('task_name')->constrained('task_sessions')->restrictOnDelete();
            $table->string('session_name', 100)->nullable()->after('task_session_id');
            $table->decimal('credit_hours', 6, 2)->default(1)->after('session');
        });

        $legacySessions = [
            'morning' => ['id' => $sessionIds['Pagi'], 'name' => 'Pagi'],
            'afternoon' => ['id' => $sessionIds['Tengah Hari'], 'name' => 'Tengah Hari'],
            'evening' => ['id' => $sessionIds['Petang'], 'name' => 'Petang'],
        ];

        foreach ($legacySessions as $legacy => $session) {
            DB::table('task_templates')
                ->where('session', $legacy)
                ->update(['task_session_id' => $session['id']]);

            DB::table('daily_checklists')
                ->where('session', $legacy)
                ->update([
                    'task_session_id' => $session['id'],
                    'session_name' => $session['name'],
            ]);
        }

        Schema::table('task_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('task_session_id')->nullable(false)->change();
        });
        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->unsignedBigInteger('task_session_id')->nullable(false)->change();
            $table->string('session_name', 100)->nullable(false)->change();
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'session']);
            $table->dropColumn('session');
            $table->index(['is_active', 'task_session_id']);
        });

        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->dropColumn('session');
            $table->index(['date', 'task_session_id']);
        });

        Schema::create('weekly_task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('task_name');
            $table->foreignId('task_session_id')->constrained('task_sessions')->restrictOnDelete();
            $table->unsignedTinyInteger('due_weekday');
            $table->decimal('credit_hours', 6, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('starts_on');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'task_session_id']);
            $table->index(['is_active', 'starts_on']);
        });

        Schema::create('weekly_materializations', function (Blueprint $table) {
            $table->date('week_start')->primary();
        });

        Schema::create('weekly_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->foreignId('weekly_task_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_session_id')->constrained('task_sessions')->restrictOnDelete();
            $table->string('task_name');
            $table->string('session_name', 100);
            $table->decimal('credit_hours', 6, 2);
            $table->date('original_due_date');
            $table->date('scheduled_date');
            $table->string('status', 20)->default('pending');
            $table->string('missed_reason', 20)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->date('completed_on')->nullable();
            $table->timestamps();

            $table->unique(['week_start', 'weekly_task_template_id'], 'weekly_occurrence_unique');
            $table->index(['scheduled_date', 'status']);
            $table->index(['completed_on', 'status']);
            $table->index(['week_start', 'status']);
        });

        Schema::create('weekly_task_postponements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_task_occurrence_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 20);
            $table->timestamps();

            $table->unique(['weekly_task_occurrence_id', 'from_date'], 'weekly_postponement_unique');
            $table->index(['from_date', 'reason']);
        });

        Schema::create('checklist_day_statuses', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->boolean('is_unavailable')->default(false);
            $table->timestamps();

            $table->index(['date', 'is_unavailable']);
        });

        Schema::create('daily_task_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_checklist_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index('daily_checklist_id');
        });

        Schema::create('weekly_task_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_task_occurrence_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index('weekly_task_occurrence_id');
        });

        Schema::create('checklist_item_positions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('task_session_id')->constrained('task_sessions')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['date', 'item_type', 'item_id'], 'checklist_item_position_unique');
            $table->unique(['date', 'task_session_id', 'position'], 'checklist_session_position_unique');
        });

        Schema::create('statistics_tracking', function (Blueprint $table) {
            $table->id();
            $table->date('started_on');
            $table->timestamps();
        });

        DB::table('statistics_tracking')->insert([
            'started_on' => CarbonImmutable::now(config('checklist.timezone', 'Asia/Kuala_Lumpur'))->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('statistics_tracking');
        Schema::dropIfExists('checklist_item_positions');
        Schema::dropIfExists('weekly_task_evidence');
        Schema::dropIfExists('daily_task_evidence');
        Schema::dropIfExists('checklist_day_statuses');
        Schema::dropIfExists('weekly_task_postponements');
        Schema::dropIfExists('weekly_task_occurrences');
        Schema::dropIfExists('weekly_materializations');
        Schema::dropIfExists('weekly_task_templates');

        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->enum('session', ['morning', 'afternoon', 'evening'])->nullable()->after('task_name');
        });
        Schema::table('task_templates', function (Blueprint $table) {
            $table->enum('session', ['morning', 'afternoon', 'evening'])->nullable()->after('task_name');
        });

        $legacySessions = [
            'Pagi' => 'morning',
            'Tengah Hari' => 'afternoon',
            'Petang' => 'evening',
        ];
        foreach ($legacySessions as $name => $legacy) {
            $sessionId = DB::table('task_sessions')->where('name', $name)->value('id');
            if ($sessionId !== null) {
                DB::table('task_templates')->where('task_session_id', $sessionId)->update(['session' => $legacy]);
                DB::table('daily_checklists')->where('task_session_id', $sessionId)->update(['session' => $legacy]);
            }
        }
        DB::table('task_templates')->whereNull('session')->update(['session' => 'morning']);
        DB::table('daily_checklists')->whereNull('session')->update(['session' => 'morning']);

        Schema::table('daily_checklists', function (Blueprint $table) {
            $table->dropForeign(['task_session_id']);
            $table->dropIndex(['date', 'task_session_id']);
            $table->dropColumn(['task_session_id', 'session_name', 'credit_hours']);
            $table->index(['date', 'session']);
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropForeign(['task_session_id']);
            $table->dropIndex(['is_active', 'task_session_id']);
            $table->dropColumn(['task_session_id', 'credit_hours', 'sort_order']);
            $table->index(['is_active', 'session']);
        });

        Schema::dropIfExists('task_sessions');
    }
};
