<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SystemSettingService
{
    protected const CACHE_PREFIX = 'system_setting:';
    protected const ALL_CACHE_KEY = 'system_settings_all';

    /**
     * Get a setting value by key with typed casting and caching.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = Cache::rememberForever(self::CACHE_PREFIX . $key, function () use ($key) {
                $setting = SystemSetting::where('key', $key)->first();
                if (! $setting) {
                    return null;
                }
                return [
                    'value' => $setting->value,
                    'type' => $setting->type,
                ];
            });

            if ($value === null) {
                return $default;
            }

            return match ($value['type']) {
                'integer' => (int) $value['value'],
                'float' => (float) $value['value'],
                'boolean' => filter_var($value['value'], FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($value['value'] ?? '[]', true),
                default => $value['value'],
            };
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Get all settings grouped by group.
     */
    public function all(): Collection
    {
        return SystemSetting::with('updater')
            ->orderBy('id')
            ->get()
            ->groupBy('group');
    }

    /**
     * Get all settings for a specific group.
     */
    public function getGroup(string $group): Collection
    {
        return SystemSetting::where('group', $group)->get();
    }

    /**
     * Update a single setting.
     */
    public function set(string $key, mixed $value, ?User $user = null): ?SystemSetting
    {
        $setting = SystemSetting::where('key', $key)->first();
        if (! $setting) {
            return null;
        }

        $oldValue = $setting->value;
        $setting->value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $setting->updated_by = $user?->id;
        $setting->save();

        Cache::forget(self::CACHE_PREFIX . $key);
        Cache::forget(self::ALL_CACHE_KEY);

        if ($oldValue !== $setting->value) {
            AuditLogger::log(
                'SETTING_UPDATED',
                "Updated setting [{$key}] from '{$oldValue}' to '{$setting->value}'",
                $user
            );
        }

        return $setting;
    }

    /**
     * Update multiple settings in bulk.
     */
    public function updateMany(array $settings, ?User $user = null): void
    {
        $changes = [];

        foreach ($settings as $key => $value) {
            $setting = SystemSetting::where('key', $key)->first();
            if ($setting) {
                $oldValue = $setting->value;
                $formattedValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

                if ($oldValue !== $formattedValue) {
                    $setting->value = $formattedValue;
                    $setting->updated_by = $user?->id;
                    $setting->save();

                    Cache::forget(self::CACHE_PREFIX . $key);
                    $changes[] = "{$key} ({$oldValue} -> {$formattedValue})";
                }
            }
        }

        Cache::forget(self::ALL_CACHE_KEY);

        if (! empty($changes)) {
            AuditLogger::log(
                'SETTINGS_UPDATED_BULK',
                'Updated system settings: ' . implode(', ', $changes),
                $user
            );
        }
    }

    /**
     * Clear all cached settings.
     */
    public function clearCache(): void
    {
        $keys = SystemSetting::pluck('key');
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
        Cache::forget(self::ALL_CACHE_KEY);
    }
}
