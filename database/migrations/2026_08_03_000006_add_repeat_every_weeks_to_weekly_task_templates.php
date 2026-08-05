<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_task_templates', function (Blueprint $table) {
            $table->unsignedTinyInteger('repeat_every_weeks')->default(1)->after('starts_on');
            $table->index(['is_active', 'repeat_every_weeks'], 'weekly_templates_active_repeat_index');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_task_templates', function (Blueprint $table) {
            $table->dropIndex('weekly_templates_active_repeat_index');
            $table->dropColumn('repeat_every_weeks');
        });
    }
};
