<?php

namespace GlpiPlugin\Drillreport;

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Html;
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

    /** Manage objectives on any drill the user can READ; write is gated by $rightname. */
    public static $checkParentRights = self::HAVE_VIEW_RIGHT_ON_ITEM;

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
        $input = parent::prepareInputForAdd($input);
        return is_array($input) ? $this->prepareCommon($input) : $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return is_array($input) ? $this->prepareCommon($input) : $input;
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

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Drill && (int) $item->getID() > 0) {
            $count = countElementsInTable(self::getTable(), [self::$items_id => $item->getID()]);
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, $item::class, self::getIcon());
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

    /**
     * Edit form for one objective, reached from the drill's Objectives tab.
     */
    public function showForm($ID, array $options = [])
    {
        $drills_id = (int) ($options['parent_id'] ?? $this->fields[self::$items_id] ?? 0);
        if (!$this->getFromDB($ID) && $ID > 0) {
            return false;
        }
        $canedit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='card'><div class='card-body'>";
        echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' data-track-changes='true'>";
        echo "<input type='hidden' name='" . self::$items_id . "' value='" . ((int) ($this->fields[self::$items_id] ?? $drills_id)) . "'>";
        if ($ID > 0) {
            echo "<input type='hidden' name='id' value='" . (int) $ID . "'>";
        }
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><td>" . __s('Objective', 'drillreport') . "</td><td>"
            . Html::input('name', ['value' => $this->fields['name'] ?? '']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Result', 'drillreport') . "</td><td>";
        Dropdown::showFromArray('result', self::resultLabels(), ['value' => $this->fields['result'] ?? 'not_met']);
        echo "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Description') . "</td><td>"
            . "<textarea class='form-control' rows='3' name='content'>" . htmlescape($this->fields['content'] ?? '') . "</textarea></td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Comment') . "</td><td>"
            . "<textarea class='form-control' rows='2' name='comment'>" . htmlescape($this->fields['comment'] ?? '') . "</textarea></td></tr>";
        echo "</table>";
        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::submit(_sx('button', $ID > 0 ? 'Save' : 'Add'), ['name' => $ID > 0 ? 'update' : 'add', 'class' => 'btn btn-primary']);
            if ($ID > 0) {
                echo "&nbsp;" . Html::submit(_sx('button', 'Delete permanently'), ['name' => 'purge', 'class' => 'btn btn-outline-danger', 'confirm' => __('Confirm the final deletion?')]);
            }
            echo "</div>";
        }
        Html::closeForm();
        echo "</div></div>";
        return true;
    }

    private static function showForDrill(Drill $drill): void
    {
        global $DB;

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id => $drill->getID()],
            'ORDER' => 'id',
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr><th>" . __s('Objective', 'drillreport') . "</th><th>" . __s('Result', 'drillreport') . "</th><th>" . __s('Comment') . "</th></tr>";
        foreach ($rows as $r) {
            $label = htmlescape($r['name']);
            if ($canedit) {
                $label = "<a href='" . htmlescape(self::getFormURLWithID($r['id']) . '&parent_id=' . $drill->getID()) . "'>{$label}</a>";
            }
            echo "<tr class='tab_bg_1'><td>{$label}</td>";
            echo "<td>" . htmlescape(self::resultLabels()[$r['result']] ?? $r['result']) . "</td>";
            echo "<td>" . htmlescape((string) $r['comment']) . "</td></tr>";
        }
        echo "</table></div>";

        if ($canedit) {
            echo "<h4 class='mt-3'>" . __s('Add an objective', 'drillreport') . "</h4>";
            (new self())->showForm(0, ['parent_id' => (int) $drill->getID()]);
        }
    }
}
