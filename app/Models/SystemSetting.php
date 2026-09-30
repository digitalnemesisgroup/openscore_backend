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
            $setting = self::where('key', $key)->first();
            if ($setting && $setting->value !== null) {
                $val = $setting->value;
                if ($val === 'false' || $val === '0' || $val === false) return false;
                if ($val === 'true' || $val === '1' || $val === true) return true;
                $decoded = json_decode($val, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
            }
        } catch (\Throwable $e) {
            // Fallback to cache or default if table not ready
            return Cache::get($key, $default);
        }

        return $default;
    }

    /**
     * Set a setting value in database
     */
    public static function set(string $key, $value)
    {
        $serialized = is_array($value) || is_object($value) || is_bool($value)
            ? json_encode($value)
            : (string) $value;

        try {
            self::updateOrCreate(
                ['key' => $key],
                ['value' => $serialized]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to persist setting {$key} to DB: " . $e->getMessage());
        }

        Cache::forever($key, $value);
        return $value;
    }
}
