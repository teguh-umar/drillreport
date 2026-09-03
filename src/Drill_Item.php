<?php

namespace GlpiPlugin\Drillreport;

use CommonDBRelation;
use CommonDBTM;
use CommonGLPI;
use Session;

/**
 * Polymorphic link between a {@see Drill} and a GLPI asset in the drill's scope,
 * carrying the per-asset RTO/RPO target vs actual and recovery outcome
 * (ISO 22398 / NIST 800-84 report components 5, 7).
 *
 * Table: glpi_plugin_drillreport_drills_items
 *   item_1 = the Drill        (plugin_drillreport_drills_id)
 *   item_2 = a polymorphic asset (itemtype / items_id) — Appliance / Computer / ...
 *
 * The link is cleaned when either side is purged (item_purge hook in setup.php).
 */
class Drill_Item extends CommonDBRelation
{
    public static $itemtype_1    = Drill::class;
    public static $items_id_1    = 'plugin_drillreport_drills_id';
    public static $take_entity_1 = false;

    public static $itemtype_2         = 'itemtype';
    public static $items_id_2         = 'items_id';
    public static $take_entity_2      = true;
    public static $checkItem_2_Rights = self::DONT_CHECK_ITEM_RIGHTS;

    public static $rightname = 'plugin_drillreport_drill';

    public static function getTypeName($nb = 0)
    {
        return _n('System in scope', 'Systems in scope', $nb, 'drillreport');
    }

    /**
     * Asset itemtypes a drill may cover. Configurable via
     * `plugin:drillreport` / `asset_itemtypes` — defaults to Appliance + Computer.
     *
     * @return list<string>
     */
    public static function getLinkedAssetItemtypes(): array
    {
        return Config::assetItemtypes();
    }

    public function getForbiddenStandardMassiveAction()
    {
        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $withtemplate) {
            return '';
        }

        // "Systems in scope" tab on the Drill form.
        if ($item instanceof Drill && (int) $item->getID() > 0) {
            $nb = countElementsInTable(self::getTable(), [self::$items_id_1 => $item->getID()]);
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb, $item::class);
        }

        // "DR drill history" tab on a supported asset.
        if (in_array($item::class, self::getLinkedAssetItemtypes(), true) && Drill::canView()) {
            $nb = countElementsInTable(self::getTable(), [
                'itemtype' => $item::class,
                'items_id' => $item->getID(),
            ]);
            return self::createTabEntry(
                __('DR drill history', 'drillreport'),
                $nb,
                $item::class,
                Drill::getIcon()
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Drill) {
            self::showForDrill($item);
            return true;
        }
        if ($item instanceof CommonDBTM && in_array($item::class, self::getLinkedAssetItemtypes(), true)) {
            self::showForAsset($item);
            return true;
        }
        return false;
    }

    private static function showForDrill(Drill $drill): void
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id_1 => $drill->getID()],
            'ORDER' => ['recovery_order', 'id'],
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __('Asset') . "</th>"
            . "<th>" . __('RTO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __('RPO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __('Recovered', 'drillreport') . "</th>"
            . "<th>" . __('Order', 'drillreport') . "</th>"
            . "</tr>";
        foreach ($rows as $r) {
            $asset = getItemForItemtype($r['itemtype']);
            $label = ($asset && $asset->getFromDB($r['items_id'])) ? $asset->getLink() : htmlescape(NOT_AVAILABLE);
            echo "<tr class='tab_bg_1'>";
            echo "<td>{$label}</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rto_target_minutes'] ?? '—', $r['rto_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rpo_target_minutes'] ?? '—', $r['rpo_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . ((int) $r['is_recovered'] === 1 ? __('Yes') : __('No')) . "</td>";
            echo "<td>" . htmlescape((string) ($r['recovery_order'] ?? '')) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }

    private static function showForAsset(CommonDBTM $item): void
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [
                'd.id AS drill_id', 'd.name AS drill_name', 'd.date_drill', 'd.overall_result',
                'i.rto_target_minutes', 'i.rto_actual_minutes', 'i.is_recovered',
            ],
            'FROM'  => self::getTable() . ' AS i',
            'INNER JOIN' => [
                Drill::getTable() . ' AS d' => ['ON' => ['i' => self::$items_id_1, 'd' => 'id']],
            ],
            'WHERE' => ['i.itemtype' => $item::class, 'i.items_id' => $item->getID()],
            'ORDER' => 'd.date_drill DESC',
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __('Drill date', 'drillreport') . "</th>"
            . "<th>" . Drill::getTypeName(1) . "</th>"
            . "<th>" . __('Overall result', 'drillreport') . "</th>"
            . "<th>" . __('RTO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __('Recovered', 'drillreport') . "</th>"
            . "</tr>";
        $drill = new Drill();
        foreach ($rows as $r) {
            $link = $drill->getFromDB($r['drill_id']) ? $drill->getLink() : htmlescape((string) $r['drill_name']);
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape((string) $r['date_drill']) . "</td>";
            echo "<td>{$link}</td>";
            echo "<td>" . htmlescape(Drill::resultLabels()[$r['overall_result']] ?? $r['overall_result']) . "</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rto_target_minutes'] ?? '—', $r['rto_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . ((int) $r['is_recovered'] === 1 ? __('Yes') : __('No')) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }
}
