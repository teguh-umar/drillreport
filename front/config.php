<?php

/**
 * DR Drill Report — configuration screen.
 *
 * Reachable from the "DR Drill Report" sidebar sector → Setup and the "Config"
 * cog in Setup → Plugins. Manages the `plugin:drillreport` config keys
 * (Config::DEFAULTS).
 *
 * CSRF is validated by core before this legacy script runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Config;
use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\Sample;
use GlpiPlugin\Pgnsuite\ConfigTab;

Session::checkRight('plugin_drillreport_config', READ);
$can_update = Session::haveRight('plugin_drillreport_config', UPDATE);

$clamp = static fn (int $v, int $min, int $max): int => max($min, min($max, $v));

// Users with core `config` READ reach this via the "PGN Suite" tab on
// Setup > General — bounce a save back there. Plugin-right-only users (no
// core `config` right, so that tab is unreachable to them) keep the
// standalone page they've always had.
$backToSuiteOrHere = static function (): void {
    if (Session::haveRight('config', READ) && class_exists(ConfigTab::class)) {
        Html::redirect(ConfigTab::getReturnUrl('drillreport'));
    }
    Html::back();
};

if (isset($_POST['update'])) {
    Session::checkRight('plugin_drillreport_config', UPDATE);

    // asset_itemtypes: multi-select → keep only real, allowed itemtypes.
    $allowed = Config::assetItemtypeChoices();
    $picked  = array_values(array_intersect(
        array_keys($allowed),
        (array) ($_POST['asset_itemtypes'] ?? [])
    ));

    \Config::setConfigurationValues('plugin:drillreport', [
        'show_business_criticity' => !empty($_POST['show_business_criticity']) ? 1 : 0,
        'show_timeline_fields'    => !empty($_POST['show_timeline_fields']) ? 1 : 0,
        'reminder_enabled'        => !empty($_POST['reminder_enabled']) ? 1 : 0,
        'reminder_month'          => $clamp((int) ($_POST['reminder_month'] ?? 11), 1, 12),
        'asset_itemtypes'         => implode(',', $picked ?: ['Appliance', 'Computer']),
        'fields_rto_key'          => trim((string) ($_POST['fields_rto_key'] ?? '')),
        'fields_rpo_key'          => trim((string) ($_POST['fields_rpo_key'] ?? '')),
    ]);
    Session::addMessageAfterRedirect(__s('Configuration updated.', 'drillreport'));
    $backToSuiteOrHere();
}

if (isset($_POST['create_sample'])) {
    Session::checkRight('plugin_drillreport_config', UPDATE);
    Session::checkRight(Drill::$rightname, CREATE);
    $id = Sample::create((int) ($_SESSION['glpiactive_entity'] ?? 0));
    Session::addMessageAfterRedirect(
        $id > 0
            ? __s('Sample drill created.', 'drillreport')
            : __s('Could not create the sample drill (see the log).', 'drillreport'),
        false,
        $id > 0 ? INFO : ERROR
    );
    $backToSuiteOrHere();
}

if (isset($_POST['remove_sample'])) {
    Session::checkRight('plugin_drillreport_config', UPDATE);
    Session::checkRight(Drill::$rightname, PURGE);
    $n = Sample::remove();
    Session::addMessageAfterRedirect(sprintf(
        _n('%d sample drill removed.', '%d sample drills removed.', $n, 'drillreport'),
        $n
    ));
    $backToSuiteOrHere();
}

Html::header(__('DR Drill Report — Setup', 'drillreport'), '', 'drillreport', 'setup');

$get = static fn (string $k): mixed => Config::get($k);

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
echo "</table></div></div>";

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3>" . __s('Assets in scope', 'drillreport') . "</h3>";
echo "<p class='text-muted'>" . __s('Which GLPI asset types a drill may cover.', 'drillreport') . "</p>";
echo "<table class='tab_cadre_fixe'>";

// Asset itemtypes — multi-select of real GLPI asset types (no free text).
$choices  = Config::assetItemtypeChoices();
$selected = Config::assetItemtypes();
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

Html::footer();
