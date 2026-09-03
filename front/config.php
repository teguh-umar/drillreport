<?php

/**
 * DR Drill Report — configuration screen.
 *
 * Reachable from Management → DR Drill Report → Setup and the "Config" cog in
 * Setup → Plugins. Manages the `plugin:drillreport` config keys (Config::DEFAULTS).
 *
 * CSRF is validated by core before this legacy script runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\ActionCategory;
use GlpiPlugin\Drillreport\Config;
use GlpiPlugin\Drillreport\Menu;
use GlpiPlugin\Drillreport\Scenario;
use GlpiPlugin\Drillreport\TestType;

Session::checkRight('plugin_drillreport_config', READ);
$can_update = Session::haveRight('plugin_drillreport_config', UPDATE);

$clamp = static fn (int $v, int $min, int $max): int => max($min, min($max, $v));

if (isset($_POST['update'])) {
    Session::checkRight('plugin_drillreport_config', UPDATE);

    \Config::setConfigurationValues('plugin:drillreport', [
        'show_business_criticity' => !empty($_POST['show_business_criticity']) ? 1 : 0,
        'show_timeline_fields'    => !empty($_POST['show_timeline_fields']) ? 1 : 0,
        'reminder_enabled'        => !empty($_POST['reminder_enabled']) ? 1 : 0,
        'reminder_month'          => $clamp((int) ($_POST['reminder_month'] ?? 11), 1, 12),
        'asset_itemtypes'         => trim((string) ($_POST['asset_itemtypes'] ?? 'Appliance,Computer')),
        'fields_rto_key'          => trim((string) ($_POST['fields_rto_key'] ?? 'rto_target_minutes')),
        'fields_rpo_key'          => trim((string) ($_POST['fields_rpo_key'] ?? 'rpo_target_minutes')),
    ]);
    Session::addMessageAfterRedirect(__s('Configuration updated.', 'drillreport'));
    Html::back();
}

Html::header(__('DR Drill Report — Setup', 'drillreport'), $_SERVER['PHP_SELF'], 'management', Menu::class);

$get = static fn (string $k): mixed => Config::get($k);

if ($can_update) {
    echo "<form method='post' action='" . htmlescape($_SERVER['PHP_SELF']) . "' data-track-changes='true'>";
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
echo "</table></div></div>";

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __s('Assets in scope', 'drillreport') . "</h3>";
echo "<p class='text-muted'>" . __s('Comma-separated GLPI itemtypes a drill may cover.', 'drillreport') . "</p>";
echo "<table class='tab_cadre_fixe'>";
echo "<tr class='tab_bg_1'><td>" . __s('Asset itemtypes', 'drillreport') . "</td><td>"
    . Html::input('asset_itemtypes', ['value' => (string) $get('asset_itemtypes'), 'size' => 50] + ($can_update ? [] : ['disabled' => 'disabled']))
    . "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __s('"fields" plugin key — RTO target', 'drillreport') . "</td><td>"
    . Html::input('fields_rto_key', ['value' => (string) $get('fields_rto_key')] + ($can_update ? [] : ['disabled' => 'disabled']))
    . "</td></tr>";
echo "<tr class='tab_bg_1'><td>" . __s('"fields" plugin key — RPO target', 'drillreport') . "</td><td>"
    . Html::input('fields_rpo_key', ['value' => (string) $get('fields_rpo_key')] + ($can_update ? [] : ['disabled' => 'disabled']))
    . "</td></tr>";
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

echo "<div class='card'><div class='card-body'>";
echo "<h3>" . __s('Reference data', 'drillreport') . "</h3><ul>";
foreach ([Scenario::class, TestType::class, ActionCategory::class] as $class) {
    echo "<li><a href='" . htmlescape($class::getSearchURL()) . "'>" . htmlescape($class::getTypeName(Session::getPluralNumber())) . "</a></li>";
}
echo "</ul></div></div>";

Html::footer();
