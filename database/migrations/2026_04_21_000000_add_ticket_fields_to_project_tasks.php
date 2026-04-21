<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * A3 Ticket 数据模型 Phase 1 — 扩展 pre_project_tasks。
 * 设计文档：docs/DESIGN_2026-04-21_TICKET_DATA_MODEL.md
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTicketFieldsToProjectTasks extends Migration
{
    public function up()
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->tinyInteger('type')->default(0)->index()
                ->comment('0=task 1=ticket')->after('parent_id');
            $table->string('ticket_source', 50)->default('')
                ->comment('工单来源：manual/email/api')->after('type');
            $table->string('ticket_category', 50)->default('')
                ->comment('工单分类：咨询/投诉/操作申请/技术问题/其他')->after('ticket_source');
            $table->unsignedBigInteger('ticket_requestor_userid')->default(0)->index()
                ->comment('工单提交人 userid，0=同创建人')->after('ticket_category');
        });
    }

    public function down()
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['ticket_requestor_userid']);
            $table->dropColumn(['type', 'ticket_source', 'ticket_category', 'ticket_requestor_userid']);
        });
    }
}
