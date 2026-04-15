<template>
    <div>
        <p><b>{{$L(msg.title)}}</b></p>
        <p>&nbsp;</p>

        <p>{{$L('文件名')}}: {{msg.name}}</p>
        <p>{{$L('文件大小')}}: {{$A.bytesToSize(msg.size)}}</p>
        <p style="margin-top:10px">
            <Button type="warning" class="no-dark-content" @click="downloadNow">{{$L('立即下载')}}</Button>
        </p>
    </div>
</template>

<script>
export default {
    props: {
        msg: Object,
    },
    data() {
        return {};
    },
    computed: {},
    methods: {
        downloadNow() {
            // 原写法 <Button :to="msg.url" target="_blank"> 被 iView 当作 vue-router 的 to 处理，
            // 对外部绝对 URL 会匹配不到路由，结果回退到默认页，用户感知就是"点了没反应"。
            // 用 window.open 主动触发下载（用户点击上下文，不会被 Chrome 弹窗拦截）。
            if (!this.msg || !this.msg.url) return;
            window.open(this.msg.url, '_blank', 'noopener');
        },
    },
}
</script>
