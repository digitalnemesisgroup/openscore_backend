<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, $default = null)
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
                $setting = self::where('key', $key)->first();
                if ($setting) {
                    $val = $setting->value;
                    if (is_null($val)) return $default;
                    if ($val === 'false' || $val === '0' || $val === false) return false;
                    if ($val === 'true' || $val === '1' || $val === true) return true;
                    
                    $decoded = json_decode($val, true);
                    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
                }
            }
        } catch (\Throwable $e) {
            // Log the error so we know if it's failing
            \Illuminate\Support\Facades\Log::error("SystemSetting::get Error for key [{$key}]: " . $e->getMessage());
        }

        // If not found in DB or DB not ready, check cache or return default
        return Cache::get($key, $default);
    }

    public static function set(string $key, $value)
    {
        $serialized = is_array($value) || is_object($value) || is_bool($value)
            ? json_encode($value)
            : (string) $value;

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
                \Illuminate\Support\Facades\DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $serialized, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to persist setting {$key} to DB: " . $e->getMessage());
        }

        Cache::forever($key, $serialized);
        return $value;
    }
}
