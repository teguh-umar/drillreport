<?php

namespace GlpiPlugin\Drillreport;

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Html;
use Session;
use Toolbox;

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

    /** Manage actions on any drill the user can READ; write is gated by $rightname. */
    public static $checkParentRights = self::HAVE_VIEW_RIGHT_ON_ITEM;

    public $dohistory = true;

    public static $rightname = 'plugin_drillreport_action';

    /** Same set as riskregister Finding::SEVERITIES (lower-cased here). */
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    public const STATUSES   = ['open', 'in_progress', 'done', 'cancelled'];

    /** Shared risk-category name used for drill Findings in riskregister. */
    public const RISK_CATEGORY = 'Disaster Recovery';

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
        if (isset($input['severity']) && !in_array($input['severity'], self::SEVERITIES, true)) {
            unset($input['severity']);
        }
        if (isset($input['status']) && !in_array($input['status'], self::STATUSES, true)) {
            unset($input['status']);
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

    // ---------------------------------------------------------------------
    // riskregister integration (docs §5)
    // ---------------------------------------------------------------------

    /**
     * Map an Action row to the riskregister Finding input array.
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
            'scope_label'    => $scope !== '' ? $scope : __('DR drill', 'drillreport'),
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

    /** True when the "Send to Risk Register" button should be offered. */
    public static function riskregisterAvailable(): bool
    {
        return class_exists('\\Plugin')
            && \Plugin::isPluginActive('riskregister')
            && class_exists('\\GlpiPlugin\\Riskregister\\Finding');
    }

    /**
     * Upsert one riskregister Finding per action of a failed drill.
     * Idempotent on the (entities_id, code) unique key; writes the returned
     * findings_id + code back onto the action row.
     *
     * @return array{sent:int, errors:int} counts
     */
    public static function sendDrillToRiskRegister(Drill $drill): array
    {
        if (!self::riskregisterAvailable()) {
            return ['sent' => 0, 'errors' => 0];
        }

        global $DB;

        $findingClass = '\\GlpiPlugin\\Riskregister\\Finding';
        $eid          = (int) $drill->fields['entities_id'];
        $year         = $drill->fields['date_drill'] ? (int) substr((string) $drill->fields['date_drill'], 0, 4) : (int) date('Y');
        $assessRef    = sprintf('%s %d', __('DR Drill', 'drillreport'), $year);

        $scope = '';
        if ((int) $drill->fields['plugin_drillreport_scenarios_id'] > 0) {
            $scope = (string) Dropdown::getDropdownName(Scenario::getTable(), (int) $drill->fields['plugin_drillreport_scenarios_id']);
        }
        if ($scope === '' || $scope === '&nbsp;') {
            $scope = (string) $drill->fields['name'];
        }

        $sent = 0;
        $errors = 0;

        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $drill->getID()]]) as $row) {
            $assetLabel = '';
            if ((int) $row['plugin_drillreport_drills_items_id'] > 0) {
                $li = new Drill_Item();
                if ($li->getFromDB((int) $row['plugin_drillreport_drills_items_id'])) {
                    $asset = getItemForItemtype($li->fields['itemtype']);
                    if ($asset && $asset->getFromDB($li->fields['items_id'])) {
                        $assetLabel = (string) $asset->getName();
                    }
                }
            }

            $input = self::toFindingInput($row, $eid, $scope, $assessRef, $assetLabel);

            /** @var \CommonDBTM $f */
            $f   = new $findingClass();
            $hit = $f->find(['entities_id' => $eid, 'code' => $input['code']]);
            $fid = 0;
            try {
                if ($hit !== []) {
                    $fid = (int) array_key_first($hit);
                    $f->update(['id' => $fid] + $input);
                } else {
                    $fid = (int) $f->add($input);
                }
            } catch (\Throwable $e) {
                Toolbox::logInFile('drillreport', 'sendToRiskRegister: ' . $e->getMessage() . "\n", true);
            }

            if ($fid > 0) {
                (new self())->update([
                    'id'                       => (int) $row['id'],
                    'riskregister_findings_id' => $fid,
                    'riskregister_code'        => $input['code'],
                ]);
                $sent++;
            } else {
                $errors++;
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    // ---------------------------------------------------------------------
    // Forms
    // ---------------------------------------------------------------------

    public function showForm($ID, array $options = [])
    {
        $drills_id = (int) ($options['parent_id'] ?? $this->fields[self::$items_id] ?? 0);
        if ($ID > 0 && !$this->getFromDB($ID)) {
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
        echo "<tr class='tab_bg_1'><td>" . __s('Finding', 'drillreport') . "</td><td>"
            . Html::input('name', ['value' => $this->fields['name'] ?? '']) . "</td>"
            . "<td>" . htmlescape(ActionCategory::getTypeName(1)) . "</td><td>";
        ActionCategory::dropdown([
            'name'  => 'plugin_drillreport_actioncategories_id',
            'value' => $this->fields['plugin_drillreport_actioncategories_id'] ?? 0,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Severity') . "</td><td>";
        Dropdown::showFromArray('severity', self::severityLabels(), ['value' => $this->fields['severity'] ?? 'medium']);
        echo "</td><td>" . __s('Status') . "</td><td>";
        Dropdown::showFromArray('status', self::statusLabels(), ['value' => $this->fields['status'] ?? 'open']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Owner group', 'drillreport') . "</td><td>";
        \Group::dropdown(['name' => 'groups_id', 'value' => $this->fields['groups_id'] ?? 0, 'condition' => ['is_assign' => 1]]);
        echo "</td><td>" . __s('Owner') . "</td><td>";
        \User::dropdown(['name' => 'users_id', 'value' => $this->fields['users_id'] ?? 0, 'right' => 'all']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __s('Due date') . "</td><td>";
        Html::showDateField('date_due', ['value' => $this->fields['date_due'] ?? '']);
        echo "</td><td></td><td></td></tr>";

        foreach ([
            'content'        => __s('What failed / observation', 'drillreport'),
            'cause'          => __s('Root cause', 'drillreport'),
            'recommendation' => __s('Recommendation', 'drillreport'),
        ] as $f => $label) {
            echo "<tr class='tab_bg_1'><td>{$label}</td><td colspan='3'>"
                . "<textarea class='form-control' rows='3' name='{$f}'>" . htmlescape($this->fields[$f] ?? '') . "</textarea></td></tr>";
        }
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

        $entries = [];
        foreach ($rows as $r) {
            $entries[] = $r;
        }

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __s('Finding', 'drillreport') . "</th>"
            . "<th>" . __s('Severity') . "</th>"
            . "<th>" . __s('Status') . "</th>"
            . "<th>" . __s('Due date') . "</th>"
            . "<th>" . __s('Sent to Risk Register', 'drillreport') . "</th>"
            . "</tr>";
        foreach ($entries as $r) {
            $label = htmlescape($r['name']);
            if ($canedit) {
                $label = "<a href='" . htmlescape(self::getFormURLWithID($r['id']) . '&parent_id=' . $drill->getID()) . "'>{$label}</a>";
            }
            echo "<tr class='tab_bg_1'><td>{$label}</td>";
            echo "<td>" . htmlescape(self::severityLabels()[$r['severity']] ?? $r['severity']) . "</td>";
            echo "<td>" . htmlescape(self::statusLabels()[$r['status']] ?? $r['status']) . "</td>";
            echo "<td>" . htmlescape((string) $r['date_due']) . "</td>";
            echo "<td>" . ((int) $r['riskregister_findings_id'] > 0 ? htmlescape((string) $r['riskregister_code']) : '—') . "</td></tr>";
        }
        echo "</table></div>";

        // "Send to Risk Register" — only for a failed drill, only when riskregister is active.
        if (
            $canedit
            && $entries !== []
            && ($drill->fields['overall_result'] ?? '') === 'fail'
            && self::riskregisterAvailable()
        ) {
            echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' class='mt-2'>";
            echo "<input type='hidden' name='" . self::$items_id . "' value='" . (int) $drill->getID() . "'>";
            echo Html::submit(
                __s('Send findings to Risk Register', 'drillreport'),
                ['name' => 'send_to_riskregister', 'class' => 'btn btn-outline-warning']
            );
            echo " <span class='text-muted'>" . __s('Creates / updates one Finding per row (idempotent).', 'drillreport') . "</span>";
            Html::closeForm();
        }

        if ($canedit) {
            echo "<h4 class='mt-3'>" . __s('Add an improvement action', 'drillreport') . "</h4>";
            (new self())->showForm(0, ['parent_id' => (int) $drill->getID()]);
        }
    }
}
