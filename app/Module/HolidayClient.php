<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Internal extension for uhomes BD daily report automation.
 */

namespace App\Module;

use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HolidayClient
{
    /**
     * 判断指定日期是否为"不汇报日"（周六/周日/中国法定节假日）。
     *
     * 调休补班（周末因调休变成工作日）按"调休不考虑"策略，仍视为休息日不建任务。
     * API 失败时降级为仅按周末规则，并在返回值中标记 fallback_used=true 供调用方告警。
     *
     * @return array{is_off: bool, fallback_used: bool, reason: string}
     */
    public static function isOffDay(Carbon $date): array
    {
        $isWeekend = in_array($date->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true);

        $apiBase = rtrim((string) config('bd_daily_report.holiday_api'), '/');
        $ttl = (int) config('bd_daily_report.holiday_cache_ttl', 86400);
        $cacheKey = 'bd_daily_report:holiday:' . $date->format('Y-m-d');

        try {
            $result = Cache::remember($cacheKey, $ttl, function () use ($apiBase, $date) {
                $resp = Http::timeout(5)->get($apiBase . '/' . $date->format('Y-m-d'));
                if (!$resp->successful()) {
                    throw new \RuntimeException('holiday api http ' . $resp->status());
                }
                $body = $resp->json();
                // timor.tech 返回 type.type：0=工作日 1=周末 2=节假日 3=调休补班
                if (!isset($body['type']['type'])) {
                    throw new \RuntimeException('holiday api schema unexpected');
                }
                return [
                    'type' => (int) $body['type']['type'],
                    'name' => (string) ($body['type']['name'] ?? ''),
                ];
            });

            $type = (int) ($result['type'] ?? 0);
            $name = (string) ($result['name'] ?? '');
            // 策略：周末、节假日、调休补班都不建任务
            $isOff = in_array($type, [1, 2, 3], true) || $isWeekend;
            $reason = $name !== '' ? $name : ($isWeekend ? 'weekend' : 'workday');

            return [
                'is_off' => $isOff,
                'fallback_used' => false,
                'reason' => $reason,
            ];
        } catch (\Throwable $e) {
            Log::warning('[BdDailyReport] holiday api failed, fallback to weekend rule: ' . $e->getMessage());
            return [
                'is_off' => $isWeekend,
                'fallback_used' => true,
                'reason' => 'api_fallback:' . $e->getMessage(),
            ];
        }
    }
}
