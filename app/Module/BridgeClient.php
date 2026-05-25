<?php
/**
 * Based on DooTask (AGPL-3.0). This file is licensed under AGPL-3.0.
 * Feishu Bridge HTTP client — calls feishu-bridge internal API.
 */

namespace App\Module;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BridgeClient
{
    private static function baseUrl(): string
    {
        return rtrim(env('FEISHU_BRIDGE_URL', 'http://127.0.0.1:3200'), '/');
    }

    private static function token(): string
    {
        return (string) env('FEISHU_BRIDGE_TOKEN', '');
    }

    private static function enabled(): bool
    {
        return (bool) env('FEISHU_BRIDGE_ENABLED', false);
    }

    private static function post(string $path): bool
    {
        if (!self::enabled()) return false;
        try {
            $res = Http::timeout(5)
                ->withHeaders(['X-Bridge-Token' => self::token()])
                ->post(self::baseUrl() . $path);
            if (!$res->successful()) {
                Log::warning("[BridgeClient] POST {$path} failed: " . $res->status());
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error("[BridgeClient] POST {$path} error: " . $e->getMessage());
            return false;
        }
    }

    public static function notifyMorning(): bool        { return self::post('/internal/notify/morning'); }
    public static function notifyCheckin(): bool        { return self::post('/internal/notify/checkin'); }
    public static function notifyEvening(): bool        { return self::post('/internal/notify/evening'); }
    public static function notifyEveningReview(): bool  { return self::post('/internal/notify/evening-review'); }
    public static function notifyAssigned(): bool       { return self::post('/internal/notify/assigned'); }
    public static function notifyOverdue(): bool        { return self::post('/internal/notify/overdue'); }
    public static function notifyProjectDigest(): bool  { return self::post('/internal/notify/project-digest'); }
}
