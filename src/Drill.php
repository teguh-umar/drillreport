<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Entity;
use Html;
use Session;

/**
 * One annual Disaster-Recovery drill report.
 *
 * Table: glpi_plugin_drillreport_drills
 * Entity-aware, soft-deletable, history-tracked, with a notepad. Report structure
 * follows ISO 22398 / NIST SP 800-84 AAR/IP — see docs/drillreport-konsep-awal.md §6.
 */
class Drill extends CommonDBTM
{
    public static $rightname = 'plugin_drillreport_drill';

    public $dohistory  = true;
    public $usenotepad = true;

    public const RESULTS  = ['pending', 'pass', 'pass_with_exceptions', 'fail'];
    public const STATUSES = ['draft', 'submitted', 'approved'];

    public static function getTypeName($nb = 0)
    {
        return _n('DR drill', 'DR drills', $nb, 'drillreport');
    }

    public static function getIcon()
    {
        return 'ti ti-refresh-alert';
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
     * @return array<string,mixed>|false
     */
    private function prepareCommon($input)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }

        if (isset($input['overall_result']) && !in_array($input['overall_result'], self::RESULTS, true)) {
            unset($input['overall_result']);
        }
        if (isset($input['status']) && !in_array($input['status'], self::STATUSES, true)) {
            unset($input['status']);
        }

        // Sign-off requires the dedicated right.
        if (
            ($input['status'] ?? null) === 'approved'
            && !Session::haveRight('plugin_drillreport_approve', UPDATE)
        ) {
            Session::addMessageAfterRedirect(
                __('You are not allowed to approve drill reports', 'drillreport'),
                false,
                ERROR
            );
            return false;
        }

        return $input;
    }

    /**
     * @param CommonGLPI $item
     * @param int        $withtemplate
     * @return array<int|string,string>|string
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Objective::class, $tabs, $options);
        $this->addStandardTab(Action::class, $tabs, $options);
        $this->addStandardTab(Drill_Item::class, $tabs, $options);
        $this->addStandardTab(\Document_Item::class, $tabs, $options);
        $this->addStandardTab(\Notepad::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    public function rawSearchOptions()
    {
        $opts = parent::rawSearchOptions();

        $opts[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'date_drill',
            'name'     => __('Drill date', 'drillreport'),
            'datatype' => 'date',
        ];
        $opts[] = [
            'id'       => 4,
            'table'    => Scenario::getTable(),
            'field'    => 'name',
            'name'     => Scenario::getTypeName(1),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id'       => 5,
            'table'    => TestType::getTable(),
            'field'    => 'name',
            'name'     => TestType::getTypeName(1),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id'         => 6,
            'table'      => self::getTable(),
            'field'      => 'overall_result',
            'name'       => __('Overall result', 'drillreport'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id'         => 7,
            'table'      => self::getTable(),
            'field'      => 'status',
            'name'       => __('Status'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id'       => 8,
            'table'    => 'glpi_groups',
            'field'    => 'completename',
            'name'     => __('Group in charge'),
            'datatype' => 'dropdown',
        ];
        $opts[] = [
            'id'       => 9,
            'table'    => self::getTable(),
            'field'    => 'rto_target_minutes',
            'name'     => __('RTO target (min)', 'drillreport'),
            'datatype' => 'number',
        ];
        $opts[] = [
            'id'       => 10,
            'table'    => self::getTable(),
            'field'    => 'rto_actual_minutes',
            'name'     => __('RTO actual (min)', 'drillreport'),
            'datatype' => 'number',
        ];
        $opts[] = [
            'id'       => 11,
            'table'    => self::getTable(),
            'field'    => 'rpo_target_minutes',
            'name'     => __('RPO target (min)', 'drillreport'),
            'datatype' => 'number',
        ];
        $opts[] = [
            'id'       => 12,
            'table'    => self::getTable(),
            'field'    => 'rpo_actual_minutes',
            'name'     => __('RPO actual (min)', 'drillreport'),
            'datatype' => 'number',
        ];

        return $opts;
    }

    /**
     * @param string $field
     * @param array<string,mixed> $values
     * @param array<string,mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $v = (string) ($values[$field] ?? '');
        return match ($field) {
            'overall_result' => self::resultLabels()[$v] ?? $v,
            'status'         => self::statusLabels()[$v] ?? $v,
            default          => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    /** @return array<string,string> */
    public static function resultLabels(): array
    {
        return [
            'pending'              => __('Pending', 'drillreport'),
            'pass'                 => __('Pass', 'drillreport'),
            'pass_with_exceptions' => __('Pass with exceptions', 'drillreport'),
            'fail'                 => __('Fail', 'drillreport'),
        ];
    }

    /** @return array<string,string> */
    public static function statusLabels(): array
    {
        return [
            'draft'     => __('Draft', 'drillreport'),
            'submitted' => __('Submitted', 'drillreport'),
            'approved'  => __('Approved', 'drillreport'),
        ];
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        return match ($field) {
            'overall_result' => Dropdown::showFromArray($name, self::resultLabels(), ['value' => $values[$field], 'display' => false]),
            'status'         => Dropdown::showFromArray($name, self::statusLabels(), ['value' => $values[$field], 'display' => false]),
            default          => parent::getSpecificValueToSelect($field, $name, $values, $options),
        };
    }

    /**
     * Skeleton form — legacy showFormHeader() layout (still valid in GLPI 11,
     * see docs/drillreport-konsep-awal.md §7.3). Twig-macro migration is planned
     * for the MVP; child records (Objective / Action / Drill_Item) already render
     * as their own tabs.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $isNew = $this->isNewID($ID);

        if (!$isNew) {
            echo "<div class='mb-2 text-end'>";
            echo "<a class='btn btn-outline-primary btn-sm' target='_blank' href='"
                . htmlescape('/plugins/drillreport/front/report.php?id=' . (int) $ID) . "'>"
                . "<i class='ti ti-file-text'></i> " . __s('View / print report', 'drillreport') . "</a>";
            echo "</div>";
        }

        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __s('Name') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name'] ?? '']) . "</td>";
        echo "<td>" . __s('Drill date', 'drillreport') . "</td>";
        echo "<td>";
        Html::showDateField('date_drill', ['value' => $this->fields['date_drill'] ?? '']);
        echo "</td>";
        echo "</tr>";

        if ($isNew) {
            echo "<tr class='tab_bg_1'><td>" . htmlescape(Entity::getTypeName(1)) . "</td><td colspan='3'>";
            Entity::dropdown([
                'name'  => 'entities_id',
                'value' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'],
            ]);
            echo "</td></tr>";
        }

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . htmlescape(Scenario::getTypeName(1)) . "</td><td>";
        Scenario::dropdown(['name' => 'plugin_drillreport_scenarios_id', 'value' => $this->fields['plugin_drillreport_scenarios_id'] ?? 0]);
        echo "</td>";
        echo "<td>" . htmlescape(TestType::getTypeName(1)) . "</td><td>";
        TestType::dropdown(['name' => 'plugin_drillreport_testtypes_id', 'value' => $this->fields['plugin_drillreport_testtypes_id'] ?? 0]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __s('Group in charge') . "</td><td>";
        \Group::dropdown(['name' => 'groups_id', 'value' => $this->fields['groups_id'] ?? 0, 'entity' => $this->fields['entities_id'] ?? -1, 'condition' => ['is_assign' => 1]]);
        echo "</td>";
        echo "<td>" . __s('Drill coordinator', 'drillreport') . "</td><td>";
        \User::dropdown(['name' => 'users_id_tech', 'value' => $this->fields['users_id_tech'] ?? 0, 'right' => 'all']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __s('Overall result', 'drillreport') . "</td><td>";
        Dropdown::showFromArray('overall_result', self::resultLabels(), ['value' => $this->fields['overall_result'] ?? 'pending']);
        echo "</td>";
        echo "<td>" . __s('Status') . "</td><td>";
        Dropdown::showFromArray('status', self::statusLabels(), ['value' => $this->fields['status'] ?? 'draft']);
        echo "</td></tr>";

        foreach ([
            'rto_target_minutes' => __s('RTO target (min)', 'drillreport'),
            'rto_actual_minutes' => __s('RTO actual (min)', 'drillreport'),
            'rpo_target_minutes' => __s('RPO target (min)', 'drillreport'),
            'rpo_actual_minutes' => __s('RPO actual (min)', 'drillreport'),
        ] as $f => $label) {
            echo "<tr class='tab_bg_1'><td>{$label}</td><td colspan='3'>"
                . Html::input($f, ['value' => $this->fields[$f] ?? '', 'type' => 'number', 'min' => '0'])
                . "</td></tr>";
        }

        foreach ([
            'objectives'        => __s('Objectives', 'drillreport'),
            'scope_summary'     => __s('Scope summary', 'drillreport'),
            'data_loss_summary' => __s('Data loss summary', 'drillreport'),
            'executive_summary' => __s('Executive summary', 'drillreport'),
            'lessons_learned'   => __s('Lessons learned', 'drillreport'),
            'comment'           => __s('Comments'),
        ] as $f => $label) {
            echo "<tr class='tab_bg_1'><td>{$label}</td><td colspan='3'>"
                . "<textarea class='form-control' rows='3' name='{$f}'>" . htmlescape($this->fields[$f] ?? '') . "</textarea>"
                . "</td></tr>";
        }

        $this->showFormButtons($options);
        return true;
    }
}
