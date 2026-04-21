<!-- Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0. -->
<template>
    <div class="page-ticket">
        <PageTitle :title="$L('提交工单')"/>
        <div class="ticket-body">
            <div v-if="!projectId" class="ticket-no-perm">
                <div class="ticket-no-perm-icon">⚠️</div>
                <div class="ticket-no-perm-text">您所在部门暂无权限提交工单，请联系管理员。</div>
            </div>

            <div v-else-if="submitted" class="ticket-success">
                <div class="ticket-success-icon">✅</div>
                <div class="ticket-success-title">工单提交成功</div>
                <div class="ticket-success-sub">工单编号 #{{ submittedId }}，{{ teamName }}团队将尽快处理。</div>
                <Button type="primary" @click="reset" style="margin-top:24px;">再提交一个</Button>
            </div>

            <div v-else class="ticket-form-wrap">
                <div class="ticket-team-tag">{{ teamName }}</div>
                <div class="ticket-form">
                    <div class="ticket-field">
                        <label class="ticket-label">工单标题 <span class="req">*</span></label>
                        <Input v-model="form.title" placeholder="一句话描述问题（20字以内）" size="large" :maxlength="50" show-word-limit/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">工单分类 <span class="req">*</span></label>
                        <Select v-model="form.category" size="large" placeholder="请选择分类">
                            <Option v-for="c in categories" :key="c" :value="c">{{ c }}</Option>
                        </Select>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">问题描述 <span class="req">*</span></label>
                        <Input v-model="form.description" type="textarea" :rows="5" placeholder="详细描述问题背景、现象、期望结果（300字以内）" :maxlength="300" show-word-limit/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">期望完成时间 <span class="opt">（选填）</span></label>
                        <DatePicker v-model="form.end_at" type="date" size="large" placeholder="如有紧急需求请选择日期" style="width:100%"/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">补充说明 <span class="opt">（选填）</span></label>
                        <Input v-model="form.supplement" type="textarea" :rows="3" placeholder="相关单号、截图说明、特殊背景等" :maxlength="200" show-word-limit/>
                    </div>

                    <div class="ticket-submitter">
                        提交人：{{ userInfo.nickname || '—' }}（{{ userInfo.email || '' }}）
                    </div>

                    <Button type="primary" size="large" long :loading="submitting" @click="onSubmit">提交工单</Button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
// Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
const STUDY_DEPT_IDS = [1, 3, 7, 8, 9, 10, 11, 12, 13, 14, 15]
const PAY_DEPT_IDS   = [2, 4, 5, 16, 17, 18, 19, 20]
const PROJECT_STUDY  = 96
const PROJECT_PAY    = 97

export default {
    name: "Ticket",
    data() {
        return {
            submitting: false,
            submitted: false,
            submittedId: null,
            form: {
                title: '',
                category: '',
                description: '',
                end_at: '',
                supplement: '',
            },
            categories: ['咨询', '投诉', '操作申请', '技术问题', '其他'],
        }
    },
    computed: {
        userInfo() {
            return this.$store.state.userInfo || {}
        },
        userDeptIds() {
            const dept = this.userInfo.department
            if (!dept) return []
            if (Array.isArray(dept)) return dept.map(Number).filter(Boolean)
            return String(dept).split(',').map(Number).filter(Boolean)
        },
        projectId() {
            if (this.userDeptIds.some(d => STUDY_DEPT_IDS.includes(d))) return PROJECT_STUDY
            if (this.userDeptIds.some(d => PAY_DEPT_IDS.includes(d))) return PROJECT_PAY
            return null
        },
        teamName() {
            if (this.projectId === PROJECT_STUDY) return '留学渠道团队'
            if (this.projectId === PROJECT_PAY)   return '缴费团队'
            return ''
        },
    },
    created() {
        if (!this.userInfo.userid) {
            this.$router.push('/login')
        }
    },
    methods: {
        async onSubmit() {
            if (!this.form.title.trim()) {
                this.$Message.error('请填写工单标题')
                return
            }
            if (!this.form.category) {
                this.$Message.error('请选择工单分类')
                return
            }
            if (!this.form.description.trim()) {
                this.$Message.error('请填写问题描述')
                return
            }
            this.submitting = true
            try {
                let content = this.form.description
                if (this.form.supplement.trim()) {
                    content += '\n\n【补充说明】\n' + this.form.supplement
                }
                const data = {
                    project_id: this.projectId,
                    column_id: 0,
                    name: this.form.title,
                    desc: content,
                    type: 1,
                    ticket_category: this.form.category,
                    ticket_requestor_userid: this.userInfo.userid,
                }
                if (this.form.end_at) {
                    const d = new Date(this.form.end_at)
                    data.end_at = d.getFullYear() + '-' +
                        String(d.getMonth()+1).padStart(2,'0') + '-' +
                        String(d.getDate()).padStart(2,'0') + ' 23:59:00'
                }
                const res = await this.$store.dispatch("call", {
                    url: "project/task__add",
                    data,
                })
                if (res && res.ret === 1) {
                    this.submittedId = res.data?.info?.id || ''
                    this.submitted = true
                } else {
                    this.$Message.error(res?.msg || '提交失败，请重试')
                }
            } catch (e) {
                this.$Message.error('提交失败，请重试')
            } finally {
                this.submitting = false
            }
        },
        reset() {
            this.submitted = false
            this.submittedId = null
            this.form = { title: '', category: '', description: '', end_at: '', supplement: '' }
        },
    },
}
</script>

<style lang="scss">
.page-ticket {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    min-height: 100vh;
    background: #f8f8f8;
    padding: 40px 16px;

    .ticket-body {
        width: 100%;
        max-width: 560px;
    }

    .ticket-no-perm,
    .ticket-success {
        background: #fff;
        border-radius: 12px;
        padding: 48px 32px;
        text-align: center;
        box-shadow: 0 4px 24px rgba(255, 90, 95, 0.12);

        &-icon { font-size: 48px; margin-bottom: 16px; }
        &-title { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
        &-sub, &-text { color: #909399; font-size: 14px; line-height: 1.6; }
    }

    .ticket-form-wrap {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(255, 90, 95, 0.12);
        overflow: hidden;
    }

    .ticket-team-tag {
        background: #FF5A5F;
        color: #fff;
        font-size: 13px;
        font-weight: 500;
        padding: 8px 20px;
    }

    .ticket-form {
        padding: 28px 32px 32px;
    }

    .ticket-field {
        margin-bottom: 20px;
    }

    .ticket-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #303133;
        margin-bottom: 6px;

        .req { color: #FF5A5F; margin-left: 2px; }
        .opt { color: #909399; font-weight: 400; font-size: 12px; }
    }

    .ticket-submitter {
        font-size: 13px;
        color: #909399;
        margin-bottom: 20px;
        padding: 10px 12px;
        background: #f8f8f8;
        border-radius: 6px;
    }
}
</style>
