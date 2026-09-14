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

Config::showForm();

Html::footer();
