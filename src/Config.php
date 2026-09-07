<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;

/**
 * Static config-value helper for context `plugin:drillreport`.
 *
 * Not used as an itemtype — front/config.php gates on the
 * `plugin_drillreport_config` right directly.
 */
class Config extends CommonDBTM
{
    public const DEFAULTS = [
        // Report / display
        'show_business_criticity' => 1,
        'show_timeline_fields'    => 0,
        'report_sections'         => 'summary,overview,participants,scope,timeline,rto_rpo,objectives,findings,evidence,approval',
        // Assets in scope
        'asset_itemtypes'         => 'Appliance,Computer',
        // Soft-read of RTO/RPO target from the `fields` plugin
        'fields_rto_key'          => 'rto_target_minutes',
        'fields_rpo_key'          => 'rpo_target_minutes',
        // Annual reminder cron
        'reminder_enabled'        => 1,
        'reminder_month'          => 11,
    ];

    public static function get(string $key): mixed
    {
        $rows = \Config::getConfigurationValues('plugin:drillreport');
        return $rows[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function setDefaults(): void
    {
        $current = \Config::getConfigurationValues('plugin:drillreport');
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing !== []) {
            \Config::setConfigurationValues('plugin:drillreport', $missing);
        }
    }

    /** @return list<class-string> resolved, existing itemtypes from `asset_itemtypes` */
    public static function assetItemtypes(): array
    {
        $out = [];
        foreach (explode(',', (string) self::get('asset_itemtypes')) as $t) {
            $t = trim($t);
            if ($t !== '' && class_exists($t)) {
                $out[] = $t;
            }
        }
        return $out ?: ['Appliance', 'Computer'];
    }
}
