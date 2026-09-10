<?php

namespace GlpiPlugin\Drillreport;

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Html;
use Session;

/**
 * One drill objective / activity and its met / partial / not-met assessment
 * (ISO 22398 / NIST 800-84 report component 9).
 *
 * Table: glpi_plugin_drillreport_objectives  (child of Drill)
 *
 * An objective carries an optional time budget (`target_minutes`) and an actual
 * `start_time` / `end_time`, so it doubles as a timed runbook activity. When a
 * time budget and both timestamps are present, `result` is auto-suggested from
 * actual vs target (still an editable field — a pass/fail objective is set by
 * hand). The drill-level RTO target/actual can be summed from these.
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

    /** actual ≤ target → met; ≤ target × this → partial; else not_met. */
    private const PARTIAL_FACTOR = 1.25;

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
        return is_array($input) ? $this->prepareCommon($input, true) : $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return is_array($input) ? $this->prepareCommon($input, false) : $input;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function prepareCommon($input, bool $isAdd)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }
        foreach (['start_time', 'end_time'] as $t) {
            if (array_key_exists($t, $input) && trim((string) $input[$t]) === '') {
                $input[$t] = 'NULL';
            }
        }
        if (array_key_exists('target_minutes', $input) && trim((string) $input['target_minutes']) === '') {
            $input['target_minutes'] = 0;
        }
        if (isset($input['result']) && !in_array($input['result'], self::RESULTS, true)) {
            unset($input['result']);
        }

        // Auto-suggest the result from timing when the operator did not set one
        // explicitly (checkbox "auto") or on a fresh add without a result.
        $wantsAuto = !empty($input['_result_auto']) || ($isAdd && !isset($input['result']));
        if ($wantsAuto) {
            $merged = array_merge($this->fields, $input);
            $suggest = self::suggestResult(
                (int) ($merged['target_minutes'] ?? 0),
                self::minutesBetween($merged['start_time'] ?? null, $merged['end_time'] ?? null)
            );
            if ($suggest !== null) {
                $input['result'] = $suggest;
            }
        }
        unset($input['_result_auto']);

        return $input;
    }

    /** Actual duration in minutes, or null when either timestamp is missing. */
    public static function minutesBetween(?string $start, ?string $end): ?int
    {
        $start = $start !== null && $start !== '' && $start !== 'NULL' ? strtotime($start) : false;
        $end   = $end !== null && $end !== '' && $end !== 'NULL' ? strtotime($end) : false;
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        return (int) round(($end - $start) / 60);
    }

    /** @return 'met'|'partial'|'not_met'|null */
    public static function suggestResult(int $targetMinutes, ?int $actualMinutes): ?string
    {
        if ($targetMinutes <= 0 || $actualMinutes === null) {
            return null;
        }
        if ($actualMinutes <= $targetMinutes) {
            return 'met';
        }
        return $actualMinutes <= $targetMinutes * self::PARTIAL_FACTOR ? 'partial' : 'not_met';
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

    public function showForm($ID, array $options = [])
    {
        $drills_id = (int) ($options['parent_id'] ?? $this->fields[self::$items_id] ?? 0);
        if (!$this->getFromDB($ID) && $ID > 0) {
            return false;
        }
        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $parentDrill = (int) ($this->fields[self::$items_id] ?? $drills_id);

        echo "<div class='card'><div class='card-body'>";
        echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' data-track-changes='true'>";
        echo "<input type='hidden' name='" . self::$items_id . "' value='{$parentDrill}'>";
        if ($ID > 0) {
            echo "<input type='hidden' name='id' value='" . (int) $ID . "'>";
        }
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><td>" . __s('Objective / activity', 'drillreport') . "</td><td colspan='3'>"
            . Html::input('name', ['value' => $this->fields['name'] ?? '']) . "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Related system', 'drillreport') . "</td><td colspan='3'>";
        self::assetDropdown($parentDrill, (int) ($this->fields['plugin_drillreport_drills_items_id'] ?? 0));
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __s('Target duration (min)', 'drillreport') . "</td><td>"
            . Html::input('target_minutes', ['type' => 'number', 'min' => 0, 'value' => (int) ($this->fields['target_minutes'] ?? 0) ?: '']) . "</td>";
        echo "<td>" . __s('Result', 'drillreport') . "</td><td>";
        Dropdown::showFromArray('result', self::resultLabels(), ['value' => $this->fields['result'] ?? 'not_met']);
        echo " <label class='ms-2'><input type='checkbox' name='_result_auto' value='1'> "
            . __s('auto from timing', 'drillreport') . "</label>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __s('Start time', 'drillreport') . "</td><td>";
        Html::showDateTimeField('start_time', ['value' => $this->fields['start_time'] ?? '']);
        echo "</td><td>" . __s('End time', 'drillreport') . "</td><td>";
        Html::showDateTimeField('end_time', ['value' => $this->fields['end_time'] ?? '']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Description') . "</td><td colspan='3'>"
            . "<textarea class='form-control' rows='3' name='content'>" . htmlescape($this->fields['content'] ?? '') . "</textarea></td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Comment') . "</td><td colspan='3'>"
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

    /** A dropdown of the drill's systems-in-scope (Drill_Item rows). */
    private static function assetDropdown(int $drills_id, int $current): void
    {
        global $DB;

        $opts = [0 => Dropdown::EMPTY_VALUE];
        foreach ($DB->request([
            'FROM'  => Drill_Item::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drills_id],
        ]) as $r) {
            $asset = getItemForItemtype((string) $r['itemtype']);
            $label = ($asset && $asset->getFromDB((int) $r['items_id'])) ? $asset->getName() : (string) $r['itemtype'];
            $opts[(int) $r['id']] = $label;
        }
        Dropdown::showFromArray('plugin_drillreport_drills_items_id', $opts, ['value' => $current]);
    }

    private static function showForDrill(Drill $drill): void
    {
        global $DB;

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id => $drill->getID()],
            'ORDER' => ['start_time', 'id'],
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __s('Objective / activity', 'drillreport') . "</th>"
            . "<th>" . __s('Target (min)', 'drillreport') . "</th>"
            . "<th>" . __s('Actual (min)', 'drillreport') . "</th>"
            . "<th>" . __s('Result', 'drillreport') . "</th>"
            . "<th>" . __s('Comment') . "</th>"
            . "</tr>";
        $sumTarget = 0;
        $sumActual = 0;
        foreach ($rows as $r) {
            $label = htmlescape($r['name']);
            if ($canedit) {
                $label = "<a href='" . htmlescape(self::getFormURLWithID($r['id']) . '&parent_id=' . $drill->getID()) . "'>{$label}</a>";
            }
            $target = (int) $r['target_minutes'];
            $actual = self::minutesBetween($r['start_time'] ?? null, $r['end_time'] ?? null);
            $sumTarget += $target;
            $sumActual += (int) ($actual ?? 0);
            echo "<tr class='tab_bg_1'><td>{$label}</td>";
            echo "<td>" . ($target > 0 ? $target : '—') . "</td>";
            echo "<td>" . ($actual !== null ? $actual : '—') . "</td>";
            echo "<td>" . htmlescape(self::resultLabels()[$r['result']] ?? $r['result']) . "</td>";
            echo "<td>" . htmlescape((string) $r['comment']) . "</td></tr>";
        }
        if ($sumTarget > 0 || $sumActual > 0) {
            echo "<tr class='tab_bg_2'><th>" . __s('Total', 'drillreport') . "</th>"
                . "<th>" . ($sumTarget ?: '—') . "</th><th>" . ($sumActual ?: '—') . "</th><th colspan='2'></th></tr>";
        }
        echo "</table></div>";

        if ($canedit) {
            echo "<p class='text-muted'>"
                . __s('The totals above can be used as the drill\'s overall RTO target / actual.', 'drillreport') . "</p>";
            echo "<h4 class='mt-3'>" . __s('Add an objective / activity', 'drillreport') . "</h4>";
            (new self())->showForm(0, ['parent_id' => (int) $drill->getID()]);
        }
    }
}
