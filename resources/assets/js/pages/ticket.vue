<!-- Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0. -->
<template>
    <div class="page-ticket">
        <PageTitle :title="$L('提交工单')"/>
        <div class="ticket-body">
            <div class="ticket-nav">
                <span class="ticket-back" @click="$router.go(-1)">← 返回</span>
            </div>
            <div v-if="!projectId" class="ticket-no-perm">
                <div class="ticket-no-perm-icon">⚠️</div>
                <div class="ticket-no-perm-text">您所在部门暂无权限提交工单，请联系管理员。</div>
            </div>

            <div v-else-if="submitted" class="ticket-success">
                <div class="ticket-success-icon">✅</div>
                <div class="ticket-success-title">工单提交成功</div>
                <div class="ticket-success-sub">工单编号 #{{ submittedId }}，{{ teamName }}团队将尽快处理。</div>
                <div style="margin-top:24px;display:flex;gap:12px;justify-content:center;">
                    <Button @click="$router.go(-1)">返回</Button>
                    <Button type="primary" @click="reset" style="background-color:#FF5A5F;border-color:#FF5A5F;">再提交一个</Button>
                </div>
            </div>

            <div v-else class="ticket-form-wrap">
                <div class="ticket-team-header">
                    <div class="ticket-team-icon">📮</div>
                    <div class="ticket-team-info">
                        <div class="ticket-team-name">{{ teamName }}</div>
                        <div class="ticket-team-subtitle">我们会尽快处理您的工单</div>
                    </div>
                </div>
                <div class="ticket-form">
                    <div class="ticket-field">
                        <label class="ticket-label ticket-label-required">工单标题 <span class="req">*</span></label>
                        <Input v-model="form.title" placeholder="一句话描述问题（20字以内）" size="large" :maxlength="50" show-word-limit/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label ticket-label-required">工单分类 <span class="req">*</span></label>
                        <Select v-model="form.category" size="large" placeholder="请选择分类">
                            <Option v-for="c in categories" :key="c" :value="c">{{ c }}</Option>
                        </Select>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label ticket-label-required">问题描述 <span class="req">*</span></label>
                        <Input v-model="form.description" type="textarea" :rows="5" placeholder="详细描述问题背景、现象、期望结果（300字以内）" :maxlength="300" show-word-limit/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">期望处理人 <span class="opt">选填</span></label>
                        <Select v-model="form.owner_userid" size="large" placeholder="不指定，由团队分配" clearable filterable>
                            <Option v-for="m in projectMembers" :key="m.userid" :value="m.userid">{{ m.nickname }}</Option>
                        </Select>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">期望完成时间 <span class="opt">选填</span></label>
                        <DatePicker v-model="form.end_at" type="datetime" size="large" placeholder="如有紧急需求请选择日期和时间" style="width:100%" format="yyyy-MM-dd HH:mm"/>
                    </div>

                    <div class="ticket-field">
                        <label class="ticket-label">补充说明 <span class="opt">选填</span></label>
                        <Input v-model="form.supplement" type="textarea" :rows="3" placeholder="相关单号、截图说明、特殊背景等" :maxlength="200" show-word-limit/>
                    </div>

                    <div class="ticket-submitter">
                        <div class="ticket-submitter-avatar">{{ userInitial }}</div>
                        <div class="ticket-submitter-info">
                            <div class="ticket-submitter-label">提交人</div>
                            <div class="ticket-submitter-name">
                                {{ userInfo.nickname || '—' }}
                                <span class="ticket-submitter-email">{{ userInfo.email || '' }}</span>
                            </div>
                        </div>
                    </div>

                    <Button type="primary" size="large" long :loading="submitting" @click="onSubmit" class="ticket-submit-btn">提交工单</Button>
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
            projectMembers: [],
            form: {
                title: '',
                category: '',
                description: '',
                end_at: '',
                supplement: '',
                owner_userid: null,
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
        // 提交人头像 initials (中文名取最后一字, 英文名取首字母)
        userInitial() {
            const name = (this.userInfo.nickname || this.userInfo.email || '?').trim()
            if (!name) return '?'
            if (/^[一-龥]/.test(name)) return name.slice(-1)
            return name[0].toUpperCase()
        },
    },
    created() {
        if (!this.userInfo.userid) {
            this.$router.push('/login')
            return
        }
        if (this.projectId) {
            this.loadMembers()
        }
    },
    // 修复: DooTask SPA 默认 keep-alive 缓存路由组件, router.push('/ticket') 不重新 mount,
    // 之前 submitted=true 的状态会保留 -> 看起来像跳到上一次提交的工单成功页
    activated() {
        this.resetState()
    },
    methods: {
        async loadMembers() {
            const res = await this.$store.dispatch("call", {
                url: "project/one",
                data: { project_id: this.projectId }
            }).catch(() => null)
            if (!res?.data) return
            const projectUsers = (res.data.project_user || [])
                .filter(m => m.userid && m.userid !== this.userInfo.userid)
            if (!projectUsers.length) return
            const userIds = projectUsers.map(m => m.userid)
            const basic = await this.$store.dispatch("call", {
                url: "users/basic",
                data: { userid: userIds },
            }).catch(() => null)
            const infoMap = {}
            if (basic?.data && Array.isArray(basic.data)) {
                basic.data.forEach(u => { infoMap[u.userid] = u })
            }
            this.projectMembers = userIds.map(uid => ({
                userid: uid,
                nickname: infoMap[uid]?.nickname || infoMap[uid]?.email || String(uid)
            }))
        },
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
                    content: content, // DooTask addTask 读 content 不读 desc, 由 generateDesc 生成摘要
                    type: 1,
                    ticket_category: this.form.category,
                    ticket_requestor_userid: this.userInfo.userid,
                }
                if (this.form.owner_userid) {
                    data.owner = [this.form.owner_userid]
                }
                if (this.form.end_at) {
                    const d = new Date(this.form.end_at)
                    const pad = n => String(n).padStart(2, '0')
                    data.end_at = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`
                }
                const res = await this.$store.dispatch("call", {
                    url: "project/task__add",
                    data,
                })
                if (res?.data) {
                    this.submittedId = res.data?.info?.id || res.data?.id || ''
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
            this.resetState()
        },
        resetState() {
            this.submitted = false
            this.submittedId = null
            this.form = {
                title: '',
                category: '',
                description: '',
                end_at: '',
                supplement: '',
                owner_userid: null,
            }
        },
    },
}
</script>

<style lang="scss">
.page-ticket {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    height: 100%;
    overflow-y: auto;
    background: #f8f8f8;
    padding: 40px 16px;

    .ticket-body {
        width: 100%;
        max-width: 560px;
    }

    .ticket-nav {
        margin-bottom: 12px;
    }

    .ticket-back {
        font-size: 14px;
        color: #909399;
        cursor: pointer;
        &:hover { color: #FF5A5F; }
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

    .ticket-team-header {
        background: linear-gradient(135deg, #FF5A5F 0%, #FF7579 100%);
        color: #fff;
        padding: 16px 24px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .ticket-team-icon {
        font-size: 28px;
        line-height: 1;
    }

    .ticket-team-name {
        font-size: 15px;
        font-weight: 600;
        line-height: 1.3;
    }

    .ticket-team-subtitle {
        font-size: 12px;
        opacity: 0.88;
        margin-top: 2px;
    }

    .ticket-form {
        padding: 28px 32px 32px;
    }

    .ticket-field {
        margin-bottom: 22px;
    }

    .ticket-label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: #303133;
        margin-bottom: 8px;
        padding-left: 10px;
        position: relative;
        line-height: 1.4;

        .req {
            color: #FF5A5F;
            margin-left: 4px;
            font-size: 13px;
        }
        .opt {
            display: inline-block;
            color: #909399;
            font-weight: 400;
            font-size: 11px;
            margin-left: 6px;
            padding: 1px 8px;
            background: #f5f5f5;
            border-radius: 10px;
            vertical-align: middle;
        }
    }

    /* 必填字段 label 左边品牌色条 */
    .ticket-label-required::before {
        content: '';
        position: absolute;
        left: 0;
        top: 3px;
        bottom: 3px;
        width: 3px;
        background: #FF5A5F;
        border-radius: 2px;
    }

    /* iView 组件 focus ring -> 品牌色 */
    .ivu-input,
    .ivu-select-selection,
    .ivu-input-wrapper .ivu-input {
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    }
    .ivu-input:focus,
    .ivu-input-focused .ivu-input,
    .ivu-select-visible .ivu-select-selection,
    .ivu-select-selection:focus {
        border-color: #FF5A5F !important;
        box-shadow: 0 0 0 3px rgba(255, 90, 95, 0.12) !important;
    }

    .ticket-submitter {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);
        border: 1px solid #feecec;
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .ticket-submitter-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #FF5A5F 0%, #FF7579 100%);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 15px;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(255, 90, 95, 0.3);
    }

    .ticket-submitter-label {
        font-size: 11px;
        color: #909399;
        line-height: 1.2;
        margin-bottom: 3px;
    }

    .ticket-submitter-name {
        font-size: 14px;
        color: #303133;
        font-weight: 500;
        line-height: 1.3;
    }

    .ticket-submitter-email {
        color: #909399;
        font-size: 12px;
        margin-left: 6px;
        font-weight: 400;
    }

    /* 提交按钮增强 */
    .ticket-submit-btn {
        background-color: #FF5A5F !important;
        border-color: #FF5A5F !important;
        height: 46px !important;
        font-size: 15px !important;
        font-weight: 600 !important;
        letter-spacing: 2px;
        box-shadow: 0 4px 12px rgba(255, 90, 95, 0.28);
        transition: all 0.2s ease-out;

        &:hover:not(:disabled):not(.ivu-btn-loading) {
            background-color: #ff4448 !important;
            border-color: #ff4448 !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(255, 90, 95, 0.38);
        }

        &:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(255, 90, 95, 0.28);
        }
    }
}
</style>
