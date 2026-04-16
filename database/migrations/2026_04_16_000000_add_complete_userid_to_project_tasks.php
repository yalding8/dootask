<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes task management.
 *
 * 为 project_tasks 表新增 complete_userid 字段，记录"谁标记了任务完成"。
 * 详见 docs/DESIGN_2026-04-16_TASK_COMPLETE_PERMISSION_AND_OPERATOR.md
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompleteUseridToProjectTasks extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('project_tasks', 'complete_userid')) {
            return;
        }
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('complete_userid')
                ->default(0)
                ->after('complete_at')
                ->comment('标记完成的操作人 userid；0=未知/系统自动');
            $table->index('complete_userid');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('project_tasks', 'complete_userid')) {
            return;
        }
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropIndex(['complete_userid']);
            $table->dropColumn('complete_userid');
        });
    }
}
