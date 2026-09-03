<?php

namespace GlpiPlugin\Drillreport;

use CommonDBChild;
use CommonGLPI;
use Session;

/**
 * One drill objective and its met / partial / not-met assessment
 * (ISO 22398 / NIST 800-84 report component 9).
 *
 * Table: glpi_plugin_drillreport_objectives  (child of Drill)
 */
class Objective extends CommonDBChild
{
    public static $itemtype = Drill::class;
    public static $items_id = 'plugin_drillreport_drills_id';

    public $dohistory = true;

    public static $rightname = 'plugin_drillreport_drill';

    public const RESULTS = ['met', 'partial', 'not_met'];

    public static function getTypeName($nb = 0)
    {
        return _n('Objective', 'Objectives', $nb, 'drillreport');
    }

    public static function getIcon()
    {
        return 'ti ti-target';
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareCommon($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommon($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function prepareCommon($input)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }
        if (isset($input['result']) && !in_array($input['result'], self::RESULTS, true)) {
            unset($input['result']);
        }
        return $input;
    }

    public static function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Drill && (int) $item->getID() > 0) {
            $count = countElementsInTable(self::getTable(), [
                self::$items_id => $item->getID(),
            ]);
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, $item::class);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Drill) {
            self::showForDrill($item);
        }
        return true;
    }

    /** @return array<string,string> */
    public static function resultLabels(): array
    {
        return [
            'met'     => __('Met', 'drillreport'),
            'partial' => __('Partially met', 'drillreport'),
            'not_met' => __('Not met', 'drillreport'),
        ];
    }

    private static function showForDrill(Drill $drill): void
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id => $drill->getID()],
            'ORDER' => 'id',
        ]);

        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr><th>" . __('Objective', 'drillreport') . "</th><th>" . __('Result', 'drillreport') . "</th><th>" . __('Comment') . "</th></tr>";
        foreach ($rows as $r) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape($r['name']) . "</td>";
            echo "<td>" . htmlescape(self::resultLabels()[$r['result']] ?? $r['result']) . "</td>";
            echo "<td>" . htmlescape((string) $r['comment']) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }
}
