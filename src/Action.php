<?php

namespace GlpiPlugin\Drillreport;

use CommonDBChild;
use CommonGLPI;
use Session;

/**
 * One finding + improvement-plan action from a drill
 * (ISO 22398 / NIST 800-84 report components 10-12).
 *
 * Table: glpi_plugin_drillreport_actions  (child of Drill)
 *
 * The content / cause / recommendation / severity / date_due columns mirror
 * riskregister's Finding so the "Send to Risk Register" button can copy them 1:1.
 * See docs/drillreport-konsep-awal.md §5.
 */
class Action extends CommonDBChild
{
    public static $itemtype = Drill::class;
    public static $items_id = 'plugin_drillreport_drills_id';

    public $dohistory = true;

    public static $rightname = 'plugin_drillreport_action';

    /** Same set as riskregister Finding::SEVERITIES (lower-cased here). */
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    public const STATUSES   = ['open', 'in_progress', 'done', 'cancelled'];

    public static function getTypeName($nb = 0)
    {
        return _n('Improvement action', 'Improvement actions', $nb, 'drillreport');
    }

    public static function getIcon()
    {
        return 'ti ti-checkup-list';
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
        if (isset($input['severity']) && !in_array($input['severity'], self::SEVERITIES, true)) {
            unset($input['severity']);
        }
        if (isset($input['status']) && !in_array($input['status'], self::STATUSES, true)) {
            unset($input['status']);
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

    /** Shared risk-category name used for drill Findings in riskregister. */
    public const RISK_CATEGORY = 'Disaster Recovery';

    /**
     * Map an Action row to the riskregister Finding input array.
     * Used by the "Send to Risk Register" button (see docs §5.1).
     *
     * @param array<string,mixed> $row      one glpi_plugin_drillreport_actions row
     * @param int                 $eid      target entity id
     * @param string              $scope    DR system / site name (Finding.scope_label)
     * @param string              $assessmentRef  e.g. "DR Drill 2026"
     * @param string              $assetLabel     free-text asset name for Reconcile
     * @return array<string,mixed>
     */
    public static function toFindingInput(
        array $row,
        int $eid,
        string $scope,
        string $assessmentRef,
        string $assetLabel = ''
    ): array {
        $input = [
            'entities_id'    => $eid,
            'code'           => sprintf('DRILL-%d-%d', (int) $row['plugin_drillreport_drills_id'], (int) $row['id']),
            'scope_label'    => $scope,
            'name'           => ($row['name'] ?: __('Drill finding', 'drillreport')),
            'severity'       => ucfirst((string) $row['severity']),
            'status'         => 'Open',
            'content'        => (string) $row['content'],
            'cause'          => (string) $row['cause'],
            'recommendation' => (string) $row['recommendation'],
            'date_due'       => $row['date_due'] ?: null,
            'assessment_ref' => $assessmentRef,
            'raw_asset_text' => $assetLabel,
        ];

        $catId = self::ensureRiskCategoryId($eid);
        if ($catId > 0) {
            $input['plugin_riskregister_riskcategories_id'] = $catId;
        }

        return $input;
    }

    /**
     * Find (or create) the "Disaster Recovery" RiskCategory in riskregister and
     * return its id. Returns 0 when riskregister is absent or the row cannot be
     * created — the Finding then lands with category 0 and is fixed on
     * riskregister's Reconcile screen.
     *
     * `ImportSupport::categoryIdByName()` returns 0 on a miss (no auto-create,
     * confirmed by riskregister 2026-09-03), and "Disaster Recovery" is not in
     * riskregister's seed — so drillreport owns this name via the itemtype API.
     */
    public static function ensureRiskCategoryId(int $eid): int
    {
        $class = 'GlpiPlugin\\Riskregister\\RiskCategory';
        if (!class_exists($class)) {
            return 0;
        }

        /** @var \CommonTreeDropdown $cat */
        $cat  = new $class();
        $hits = $cat->find(['name' => self::RISK_CATEGORY, 'entities_id' => $eid]);
        if ($hits !== []) {
            return (int) array_key_first($hits);
        }

        $newId = $cat->add(['name' => self::RISK_CATEGORY, 'entities_id' => $eid]);
        return $newId ? (int) $newId : 0;
    }

    /** @return array<string,string> */
    public static function severityLabels(): array
    {
        return [
            'critical' => __('Critical'),
            'high'     => __('High'),
            'medium'   => __('Medium'),
            'low'      => __('Low'),
            'info'     => __('Info', 'drillreport'),
        ];
    }

    /** @return array<string,string> */
    public static function statusLabels(): array
    {
        return [
            'open'        => __('Open'),
            'in_progress' => __('In progress', 'drillreport'),
            'done'        => __('Done', 'drillreport'),
            'cancelled'   => __('Cancelled', 'drillreport'),
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
        echo "<tr>"
            . "<th>" . __('Finding', 'drillreport') . "</th>"
            . "<th>" . __('Severity') . "</th>"
            . "<th>" . __('Status') . "</th>"
            . "<th>" . __('Due date') . "</th>"
            . "<th>" . __('Sent to Risk Register', 'drillreport') . "</th>"
            . "</tr>";
        foreach ($rows as $r) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape($r['name']) . "</td>";
            echo "<td>" . htmlescape(self::severityLabels()[$r['severity']] ?? $r['severity']) . "</td>";
            echo "<td>" . htmlescape(self::statusLabels()[$r['status']] ?? $r['status']) . "</td>";
            echo "<td>" . htmlescape((string) $r['date_due']) . "</td>";
            echo "<td>" . ((int) $r['riskregister_findings_id'] > 0 ? htmlescape((string) $r['riskregister_code']) : '—') . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }
}
