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

define('PLUGIN_DRILLREPORT_VERSION', '0.3.7');
define('PLUGIN_DRILLREPORT_MIN_GLPI', '11.0.0');
define('PLUGIN_DRILLREPORT_MAX_GLPI', '11.9.99');

function plugin_version_drillreport(): array
{
    return [
        'name'         => 'DR Drill Report',
        'version'      => PLUGIN_DRILLREPORT_VERSION,
        'author'       => 'PGN ICT',
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
    global $PLUGIN_HOOKS;

    // GLPI-managed CSRF — the plugin never calls Session::checkCSRF() itself.
    $PLUGIN_HOOKS['csrf_compliant']['drillreport'] = true;

    // Dropdowns (Setup > Dropdowns).
    Plugin::registerClass(Scenario::class);
    Plugin::registerClass(TestType::class);
    Plugin::registerClass(ActionCategory::class);

    // Core itemtype + its child records.
    Plugin::registerClass(Drill::class);
    Plugin::registerClass(Objective::class, ['addtabon' => Drill::class]);
    Plugin::registerClass(Action::class, ['addtabon' => Drill::class]);

    // Drill <-> asset polymorphic link: "DR Drill history" tab on every
    // supported asset + a "Systems in scope" tab on the drill.
    Plugin::registerClass(Drill_Item::class, ['addtabon' => Drill::class]);
    foreach (Drill_Item::getLinkedAssetItemtypes() as $itemtype) {
        Plugin::registerClass(Drill_Item::class, ['addtabon' => $itemtype]);
    }

    // GLPI core does not auto-clean plugin polymorphic link tables — wire the
    // purge cascade for Drill_Item ourselves.
    $dr_item_purge = static function ($item): void {
        (new Drill_Item())->cleanDBonItemDelete($item->getType(), (int) $item->getID());
    };
    foreach (Drill_Item::getLinkedAssetItemtypes() as $itemtype) {
        $PLUGIN_HOOKS['item_purge']['drillreport'][$itemtype] = $dr_item_purge;
    }
    $PLUGIN_HOOKS['item_purge']['drillreport'][Drill::class] = $dr_item_purge;

    // "DR Drill Report" as its own top-level sidebar sector.
    // Array-of-classes value => NEW sector; a bare string only appends to one.
    Plugin::registerClass(Menu::class);
    $PLUGIN_HOOKS['menu_toadd']['drillreport'] = ['drillreport' => [Menu::class]];

    // Setup landing page (cog next to the plugin in Setup > Plugins).
    $PLUGIN_HOOKS['config_page']['drillreport'] = 'front/config.php';

    // Rights-management tab on the core Setup > Profiles form.
    Plugin::registerClass(DRProfile::class, ['addtabon' => 'Profile']);
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
