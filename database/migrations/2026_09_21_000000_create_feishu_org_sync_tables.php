<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFeishuOrgSyncTables extends Migration
{
    public function up()
    {
        Schema::create('feishu_org_department_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_root', 64);
            $table->string('source_department_id', 64);
            $table->unsignedBigInteger('dootask_department_id');
            $table->timestamps();
            $table->unique(['source_root', 'source_department_id'], 'feishu_org_source_department_unique');
            $table->unique('dootask_department_id', 'feishu_org_target_department_unique');
        });
        Schema::create('feishu_org_sync_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('plan_digest', 64)->unique();
            $table->string('status', 32)->index();
            $table->string('source_root', 64);
            $table->unsignedBigInteger('target_root');
            $table->longText('pre_snapshot')->nullable();
            $table->longText('post_snapshot')->nullable();
            $table->text('counters')->nullable();
            $table->string('error_code', 64)->default('');
            $table->unsignedBigInteger('operator_userid')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('feishu_org_sync_batches');
        Schema::dropIfExists('feishu_org_department_mappings');
    }
}
