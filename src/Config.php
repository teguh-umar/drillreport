<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;
use Dropdown;
use Glpi\Asset\AssetDefinition;
use Html;
use Session;

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

        $fix = [];

        // Self-heal: an earlier default seeded a placeholder `fields` key that is
        // very unlikely to be a real column. If it isn't one, blank it so the
        // Setup screen doesn't show "(not found)" out of the box.
        foreach (['fields_rto_key', 'fields_rpo_key'] as $k) {
            $v = trim((string) ($current[$k] ?? ''));
            if ($v !== '' && !self::fieldsColumnExists($v)) {
                $fix[$k] = '';
            }
        }

        // Self-heal: a stored `report_sections` from an older plugin version can
        // hold section keys that no longer exist (and miss new ones). If any
        // stored token is unknown to Report::SECTIONS, reset to the default.
        $stored = array_filter(array_map('trim', explode(',', (string) ($current['report_sections'] ?? ''))));
        if ($stored !== [] && array_diff($stored, Report::SECTIONS) !== []) {
            $fix['report_sections'] = self::DEFAULTS['report_sections'];
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

    /**
     * Enabled report sections from `report_sections`, in report order. Unknown
     * keys are dropped; nothing left means every section.
     *
     * @return list<string>
     */
    public static function reportSections(): array
    {
        return array_values(array_intersect(
            Report::SECTIONS,
            array_map('trim', explode(',', (string) self::get('report_sections')))
        )) ?: Report::SECTIONS;
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
        $out += self::customAssetItemtypes();
        asort($out);
        return $cache = $out;
    }

    /**
     * Active GLPI 11 custom asset definitions (Setup > Asset definitions), read
     * straight from the definitions table. plugin_init runs before core boots
     * custom objects (InitializePlugins 110 > CustomObjectsBoot 100, 11.0/bugfixes
     * ListenersPriority), so at that point they are neither in
     * $CFG_GLPI['asset_types'] nor autoloadable — class_exists() is false and
     * they would be silently dropped from tabs, the picker and the purge hook.
     *
     * @return array<class-string, string> itemtype => label
     */
    private static function customAssetItemtypes(): array
    {
        global $DB;

        $out = [];
        $rows = $DB->request([
            'SELECT' => ['system_name', 'label'],
            'FROM'   => AssetDefinition::getTable(),
            'WHERE'  => ['is_active' => 1],
        ]);
        foreach ($rows as $r) {
            $class = AssetDefinition::getCustomObjectNamespace() . '\\' . $r['system_name']
                . AssetDefinition::getCustomObjectClassSuffix();
            $out[$class] = (string) ($r['label'] ?: $r['system_name']);
        }
        return $out;
    }

    /**
     * The config screen's body (everything between the page header/footer) —
     * shared by the standalone `front/config.php` page and the "PGN Suite"
     * tab section ({@see SuiteConfig::render()}). Both post to the same
     * controller (`front/config.php`), so this only renders, never handles POST.
     */
    // Instance method to match the parent: CommonDBTM::showForm($ID, array
    // $options = []) is an instance method, not static (11.0/bugfixes
    // src/CommonDBTM.php:543) — declaring this static fatals on class load
    // ("Cannot make non static method static"), which aborts hook.php
    // before plugin_drillreport_install() is defined. Caller must use
    // (new Config())->showForm() — see front/config.php and SuiteConfig.php.
    public function showForm($ID = 0, array $options = []): void
    {
        $can_update = Session::haveRight('plugin_drillreport_config', UPDATE);
        // GLPI 11: $_SERVER['PHP_SELF'] points at public/index.php, not the
        // controller — build the real URL regardless of where this renders from.
        $self = Html::getPrefixedUrl('/plugins/drillreport/front/config.php');
        $get  = static fn (string $k): mixed => self::get($k);

        if ($can_update) {
            echo "<form method='post' action='" . htmlescape($self) . "' data-track-changes='true'>";
        } else {
            echo "<div class='alert alert-info'>" . __s('You have read-only access to this configuration.', 'drillreport') . "</div>";
        }

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3>" . __s('Report display', 'drillreport') . "</h3>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><td>" . __s('Show asset Business Criticality column in the report', 'drillreport') . "</td><td>";
        Dropdown::showYesNo('show_business_criticity', (int) $get('show_business_criticity'), -1, ['readonly' => !$can_update]);
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Show timeline fields (declaration / recovery / validation time)', 'drillreport') . "</td><td>";
        Dropdown::showYesNo('show_timeline_fields', (int) $get('show_timeline_fields'), -1, ['readonly' => !$can_update]);
        echo "</td></tr>";
        // Report sections — multi-select of Report::SECTIONS (no free text).
        echo "<tr class='tab_bg_1'><td>" . __s('Sections shown in the printable report', 'drillreport')
            . "<br><span class='text-muted'>" . __s('Sections keep their fixed order and are renumbered. Leave empty to show all.', 'drillreport') . "</span></td><td>";
        Dropdown::showFromArray('report_sections', Report::sectionTitles(), [
            'values'   => self::reportSections(),
            'multiple' => true,
            'width'    => '100%',
            'readonly' => !$can_update,
        ]);
        echo "</td></tr>";
        echo "</table></div></div>";

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3>" . __s('Assets in scope', 'drillreport') . "</h3>";
        echo "<p class='text-muted'>" . __s('Which GLPI asset types a drill may cover.', 'drillreport') . "</p>";
        echo "<table class='tab_cadre_fixe'>";

        // Asset itemtypes — multi-select of real GLPI asset types (no free text).
        $choices  = self::assetItemtypeChoices();
        $selected = self::assetItemtypes();
        echo "<tr class='tab_bg_1'><td>" . __s('Asset types', 'drillreport') . "</td><td>";
        Dropdown::showFromArray('asset_itemtypes', $choices, [
            'values'   => $selected,
            'multiple' => true,
            'width'    => '100%',
            'readonly' => !$can_update,
        ]);
        echo "</td></tr>";

        // RTO/RPO target key — dropdown of columns the `fields` plugin actually exposes.
        $fieldsActive = class_exists('\\Plugin') && \Plugin::isPluginActive('fields');
        $fieldCols    = $fieldsActive ? Drill_Item::availableFieldsColumns() : [];

        $keyField = static function (string $name, string $label) use ($get, $can_update, $fieldsActive, $fieldCols): string {
            $cur = (string) $get($name);
            if ($fieldsActive && $fieldCols !== []) {
                $opts = ['' => __('— not used —', 'drillreport')];
                foreach ($fieldCols as $c) {
                    $opts[$c] = $c;
                }
                if ($cur !== '' && !isset($opts[$cur])) {
                    $opts[$cur] = $cur . ' ' . __('(not found)', 'drillreport');
                }
                return "<tr class='tab_bg_1'><td>{$label}</td><td>"
                    . Dropdown::showFromArray($name, $opts, ['value' => $cur, 'display' => false, 'width' => '100%', 'readonly' => !$can_update])
                    . "</td></tr>";
            }
            return "<tr class='tab_bg_1'><td>{$label}</td><td>"
                . Html::input($name, ['value' => $cur, 'size' => 30] + ($can_update ? [] : ['disabled' => 'disabled']))
                . "</td></tr>";
        };

        echo "</table>";
        echo "<h4 class='mt-3'>" . __s('RTO / RPO target from the "fields" plugin', 'drillreport') . "</h4>";
        if (!$fieldsActive) {
            echo "<div class='alert alert-info'>"
                . __s('The "fields" plugin is not active — targets can only be entered on each drill.', 'drillreport')
                . "</div>";
        } elseif ($fieldCols === []) {
            echo "<div class='alert alert-warning'>"
                . __s('The "fields" plugin is active but has no custom field on any itemtype yet. Create the RTO/RPO fields there first.', 'drillreport')
                . "</div>";
        } else {
            echo "<p class='text-muted'>"
                . __s('Pick the "fields" plugin column that holds each target. Leave "not used" to always enter targets on the drill.', 'drillreport')
                . "</p>";
        }
        echo "<table class='tab_cadre_fixe'>";
        echo $keyField('fields_rto_key', __s('RTO target column', 'drillreport'));
        echo $keyField('fields_rpo_key', __s('RPO target column', 'drillreport'));
        echo "</table></div></div>";

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3>" . __s('Annual reminder', 'drillreport') . "</h3>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><td>" . __s('Enable the "drill not submitted" reminder', 'drillreport') . "</td><td>";
        Dropdown::showYesNo('reminder_enabled', (int) $get('reminder_enabled'), -1, ['readonly' => !$can_update]);
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Reminder month (1–12)', 'drillreport') . "</td><td>"
            . Html::input('reminder_month', ['type' => 'number', 'min' => 1, 'max' => 12, 'value' => (int) $get('reminder_month')] + ($can_update ? [] : ['disabled' => 'disabled']))
            . "</td></tr>";
        echo "</table></div></div>";

        if ($can_update) {
            echo "<div class='center mb-3'>" . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . "</div>";
            Html::closeForm();
        }

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3>" . __s('Reference data', 'drillreport') . "</h3><ul>";
        foreach ([Scenario::class, TestType::class, ActionCategory::class] as $class) {
            echo "<li><a href='" . htmlescape($class::getSearchURL()) . "'>" . htmlescape($class::getTypeName(Session::getPluralNumber())) . "</a></li>";
        }
        echo "</ul></div></div>";

        // ---- Sample data ---------------------------------------------------
        if ($can_update && Session::haveRight(Drill::$rightname, CREATE)) {
            $sampleId = Sample::drillId();
            echo "<div class='card'><div class='card-body'>";
            echo "<h3>" . __s('Sample data', 'drillreport') . "</h3>";
            echo "<p class='text-muted'>"
                . __s('A fully worked demo drill (objectives, improvement actions, a system in scope) so you have a concrete reference. Every row is prefixed "[SAMPLE]".', 'drillreport')
                . "</p>";
            echo "<form method='post' action='" . htmlescape($self) . "'>";
            if ($sampleId > 0) {
                echo "<a class='btn btn-outline-secondary' href='" . htmlescape(Drill::getFormURLWithID($sampleId)) . "'>"
                    . "<i class='ti ti-external-link'></i> " . __s('Open the sample drill', 'drillreport') . "</a> ";
                echo Html::submit(__s('Remove sample data', 'drillreport'), [
                    'name'    => 'remove_sample',
                    'class'   => 'btn btn-outline-danger',
                    'confirm' => __('Confirm the final deletion?'),
                ]);
            } else {
                echo Html::submit(__s('Create the sample drill', 'drillreport'), [
                    'name'  => 'create_sample',
                    'class' => 'btn btn-outline-primary',
                ]);
            }
            Html::closeForm();
            echo "</div></div>";
        }
    }
}
