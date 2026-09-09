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
        'report_sections'         => 'summary,overview,scope,timeline,rto_rpo,objectives,findings,remediation,approval',
        // Assets in scope
        'asset_itemtypes'         => 'Appliance,Computer',
        // Soft-read of RTO/RPO target from the `fields` plugin — empty = off
        // (admin picks a real column on the Setup screen when they want it).
        'fields_rto_key'          => '',
        'fields_rpo_key'          => '',
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

        // Self-heal: an earlier default seeded a placeholder `fields` key that is
        // very unlikely to be a real column. If it isn't one, blank it so the
        // Setup screen doesn't show "(not found)" out of the box.
        $fix = [];
        foreach (['fields_rto_key', 'fields_rpo_key'] as $k) {
            $v = trim((string) ($current[$k] ?? ''));
            if ($v !== '' && !self::fieldsColumnExists($v)) {
                $fix[$k] = '';
            }
        }
        if ($fix !== []) {
            \Config::setConfigurationValues('plugin:drillreport', $fix);
        }
    }

    private static function fieldsColumnExists(string $column): bool
    {
        global $DB;

        if (!class_exists('\\Plugin') || !\Plugin::isPluginActive('fields')) {
            return false;
        }
        foreach ($DB->listTables('glpi\\_plugin\\_fields\\_%') as $trow) {
            if ($DB->fieldExists($trow['TABLE_NAME'], $column)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<class-string> resolved, existing itemtypes from `asset_itemtypes` */
    public static function assetItemtypes(): array
    {
        $choices = self::assetItemtypeChoices();
        $out = [];
        foreach (explode(',', (string) self::get('asset_itemtypes')) as $t) {
            $t = trim($t);
            if ($t !== '' && isset($choices[$t])) {
                $out[] = $t;
            }
        }
        return $out ?: ['Appliance', 'Computer'];
    }

    /**
     * The GLPI itemtypes a drill may legitimately cover — the fixed set the
     * Setup multi-select is built from. Curated DR-relevant base list, plus every
     * standard asset / management type GLPI declares at runtime (which includes
     * GLPI 11 custom asset definitions).
     *
     * @return array<class-string, string> itemtype => localized name, name-sorted
     */
    public static function assetItemtypeChoices(): array
    {
        global $CFG_GLPI;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $candidates = array_merge(
            ['Computer', 'Appliance', 'Cluster', 'Software', 'DatabaseInstance', 'Database'],
            (array) ($CFG_GLPI['asset_types'] ?? []),
            (array) ($CFG_GLPI['management_types'] ?? []),
        );

        $out = [];
        foreach (array_unique($candidates) as $class) {
            if (!is_string($class) || !class_exists($class) || !is_a($class, CommonDBTM::class, true)) {
                continue;
            }
            $out[$class] = $class::getTypeName(1);
        }
        asort($out);
        return $cache = $out;
    }
}
