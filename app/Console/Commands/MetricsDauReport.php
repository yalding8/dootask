<?php
// Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
//
// MetricsDauReport — 输出 DAU/WAU/MAU 活跃用户数, 支持 --snapshot 模式追加快照
// 用于 M2 KR1 测量 (WAU ≥ 800 连续 4 周达标判定).
//
// 设计取舍 (与 PRD §M2 DAUTracker 不同):
//   - 不新建 middleware + 新表 — DooTask users 表已有 line_at (最后在线时间, 30s 接口刷新)
//   - 仅读 users.line_at, 不引入 schema 变更
//   - 快照写到 storage/app/metrics/wau-snapshots.jsonl (一行 JSON, 不需要新 schema)
//   - KR1 阈值在脚本里硬编码 (800), 改阈值改源码即可
//
// 详见 docs/DESIGN_2026-04-18_dau_tracker.md (待补)

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MetricsDauReport extends Command
{
    protected $signature = 'metrics:dau-report
                            {--snapshot : 把当前快照追加到 storage/app/metrics/wau-snapshots.jsonl 用于 KR1 4 周连续判定}';

    protected $description = '输出 DAU/WAU/MAU 活跃用户数; --snapshot 模式追加快照供 KR1 判定';

    /** M2 KR1 阈值: WAU ≥ 800 连续 4 周达标 */
    const KR1_WAU_THRESHOLD = 800;
    const KR1_CONSECUTIVE_WEEKS = 4;

    public function handle()
    {
        $now = Carbon::now();

        // 仅统计未禁用用户 (disable_at IS NULL)
        $base = User::whereNull('disable_at');
        $total = (clone $base)->count();

        $dau = (clone $base)->where('line_at', '>=', $now->copy()->subDay())->count();
        $wau = (clone $base)->where('line_at', '>=', $now->copy()->subDays(7))->count();
        $mau = (clone $base)->where('line_at', '>=', $now->copy()->subDays(30))->count();

        $pct = fn(int $n) => $total > 0 ? round($n * 100 / $total, 1) . '%' : '0%';

        $this->info("活跃用户报告 (基准时间: {$now->toDateTimeString()})");
        $this->table(
            ['指标', '人数', '占总用户比例'],
            [
                ['DAU (1 天活跃)', $dau, $pct($dau)],
                ['WAU (7 天活跃)', $wau, $pct($wau)],
                ['MAU (30 天活跃)', $mau, $pct($mau)],
                ['总未禁用用户', $total, '100%'],
            ]
        );

        if (!$this->option('snapshot')) {
            return Command::SUCCESS;
        }

        // ───── --snapshot 模式: 追加 + KR1 4 周判定 ─────
        $file = storage_path('app/metrics/wau-snapshots.jsonl');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $row = [
            'snapshot_at' => $now->toIso8601String(),
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'total' => $total,
        ];
        file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $this->info("✓ 快照写入: {$file}");

        // KR1 判定: 最近 N 周快照 WAU 是否全部 ≥ 阈值
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recent = array_slice($lines, -self::KR1_CONSECUTIVE_WEEKS);
        $waus = array_map(fn($s) => json_decode($s)->wau ?? 0, $recent);

        if (count($waus) < self::KR1_CONSECUTIVE_WEEKS) {
            $this->line("KR1 判定: 快照仅 " . count($waus) . " 期, 不足 " . self::KR1_CONSECUTIVE_WEEKS . " 周, 暂不评判");
            return Command::SUCCESS;
        }

        $allMet = count(array_filter($waus, fn($w) => $w >= self::KR1_WAU_THRESHOLD)) === count($waus);
        $minW = min($waus);
        $passRate = round($wau * 100 / self::KR1_WAU_THRESHOLD, 1);

        $this->line("最近 " . self::KR1_CONSECUTIVE_WEEKS . " 周 WAU: " . implode(' / ', $waus) . " (阈值 " . self::KR1_WAU_THRESHOLD . ")");
        $this->line($allMet
            ? "KR1 判定: ✅ 达标 (4 周全部 ≥ " . self::KR1_WAU_THRESHOLD . ")"
            : "KR1 判定: ❌ 未达标 (最低 " . $minW . ", 当前 WAU 达成率 {$passRate}%)");

        return Command::SUCCESS;
    }
}
