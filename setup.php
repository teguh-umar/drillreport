<?php

/**
 * drillreport — GLPI 11 plugin for annual Disaster-Recovery drill logging.
 *
 * @license GPL-3.0-or-later
 * @author  PGN ICT
 */

use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\Objective;
use GlpiPlugin\Drillreport\Action;
use GlpiPlugin\Drillreport\Drill_Item;
use GlpiPlugin\Drillreport\Scenario;
use GlpiPlugin\Drillreport\TestType;
use GlpiPlugin\Drillreport\ActionCategory;
use GlpiPlugin\Drillreport\Menu;
use GlpiPlugin\Drillreport\Profile as DRProfile;
use GlpiPlugin\Drillreport\SuiteConfig;
use GlpiPlugin\Drillreport\AssetSections;
use GlpiPlugin\Pgnsuite\AssetTab;
use GlpiPlugin\Pgnsuite\ConfigTab;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

define('PLUGIN_DRILLREPORT_VERSION', '0.7.15');
define('PLUGIN_DRILLREPORT_MIN_GLPI', '11.0.0');
define('PLUGIN_DRILLREPORT_MAX_GLPI', '11.9.99');

function plugin_version_drillreport(): array
{
    return [
        'name'         => 'DR Drill Report',
        'version'      => PLUGIN_DRILLREPORT_VERSION,
        'author'       => 'ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DRILLREPORT_MIN_GLPI,
                'max' => PLUGIN_DRILLREPORT_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_drillreport_check_prerequisites(): bool
{
    return true; // version gate handled by 'requirements' above
}

function plugin_drillreport_check_config(): bool
{
    return true;
}

function plugin_init_drillreport(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // GLPI-managed CSRF — the plugin never calls Session::checkCSRF() itself.
    $PLUGIN_HOOKS['csrf_compliant']['drillreport'] = true;

    // Make Drill / Action selectable as an itemtype in Setup > Notifications
    // (Notification + NotificationTemplate "add" forms). Resolution to
    // NotificationTargetDrill / NotificationTargetAction is by naming
    // convention (NotificationTarget::getInstanceClass()) — no extra hook.
    $CFG_GLPI['notificationtemplates_types'][] = Drill::class;
    $CFG_GLPI['notificationtemplates_types'][] = Action::class;

    // Dropdowns (Setup > Dropdowns).
    Plugin::registerClass(Scenario::class);
    Plugin::registerClass(TestType::class);
    Plugin::registerClass(ActionCategory::class);

    // Core itemtype + its child records.
    Plugin::registerClass(Drill::class);
    Plugin::registerClass(Objective::class, ['addtabon' => Drill::class]);
    Plugin::registerClass(Action::class, ['addtabon' => Drill::class]);

    // Drill <-> asset polymorphic link: "Systems in scope" tab on the drill.
    // The asset side ("DR drill history") is a section of the shared
    // suite asset tab, wired below — not a tab of its own.
    Plugin::registerClass(Drill_Item::class, ['addtabon' => Drill::class]);

    // GLPI core does not auto-clean plugin polymorphic link tables — wire the
    // purge cascade for Drill_Item ourselves.
    $dr_item_purge = static function ($item): void {
        (new Drill_Item())->cleanDBonItemDelete($item->getType(), (int) $item->getID());
    };
    foreach (Drill_Item::getLinkedAssetItemtypes() as $itemtype) {
        $PLUGIN_HOOKS['item_purge']['drillreport'][$itemtype] = $dr_item_purge;
    }
    $PLUGIN_HOOKS['item_purge']['drillreport'][Drill::class] = $dr_item_purge;

    // One entry in the suite's shared sidebar sector (lib/pgnsuite/Menu.php,
    // same byte-identical rule as ConfigTab / AssetTab below — hub file §5).
    // Menu::entry() supplies the entry and its options (DR drills /
    // Improvement actions). Users must log in again to see a menu change.
    if (!class_exists(SuiteMenu::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/Menu.php';
    }
    SuiteMenu::register('drillreport');
    $PLUGIN_HOOKS[SuiteMenu::HOOK]['drillreport'] = Menu::class . '::entry';

    // Setup landing page (cog next to the plugin in Setup > Plugins).
    $PLUGIN_HOOKS['config_page']['drillreport'] = 'front/config.php';

    // Rights-management tab on the core Setup > Profiles form.
    Plugin::registerClass(DRProfile::class, ['addtabon' => 'Profile']);

    // Shared suite tab on Setup > General (lib/pgnsuite/ConfigTab.php,
    // byte-identical copy from every PGN plugin — see hub file §5). Never
    // edit this plugin's copy on its own; the class only defines the tab
    // shell + reads $PLUGIN_HOOKS[ConfigTab::HOOK], it does not depend on
    // anything else drillreport-specific.
    if (!class_exists(ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    ConfigTab::register();
    $PLUGIN_HOOKS[ConfigTab::HOOK]['drillreport'] = SuiteConfig::class . '::section';

    // Shared suite tab on asset forms (lib/pgnsuite/AssetTab.php, same
    // byte-identical rule as ConfigTab above — hub file §5). drillreport adds
    // its "DR drill history" section on every configured asset itemtype.
    if (!class_exists(AssetTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
    }
    AssetTab::register(Drill_Item::getLinkedAssetItemtypes());
    $PLUGIN_HOOKS[AssetTab::HOOK]['drillreport'] = AssetSections::class . '::sections';
}

/**
 * Reference dropdowns for Setup > Dropdowns.
 *
 * GLPI 11 reads this via Hooks::AUTO_GET_DROPDOWN ('getDropdown'); the historical
 * $PLUGIN_HOOKS['dropdowns'] key is not read anymore.
 *
 * @return array<class-string, string>
 */
function plugin_drillreport_getDropdown(): array
{
    return [
        Scenario::class       => Scenario::getTypeName(2),
        TestType::class       => TestType::getTypeName(2),
        ActionCategory::class => ActionCategory::getTypeName(2),
    ];
}
