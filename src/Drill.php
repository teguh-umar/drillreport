<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
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
    /** Nullable numeric / date / datetime columns — an empty form field becomes NULL. */
    private const NULLABLE_FIELDS = [
        'date_drill', 'declaration_time', 'recovery_time', 'validation_time', 'approval_date',
        'rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes',
    ];

    private function prepareCommon($input)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
        }

        // MySQL strict mode rejects '' for INT / DATE / DATETIME columns.
        foreach (self::NULLABLE_FIELDS as $k) {
            if (array_key_exists($k, $input) && trim((string) $input[$k]) === '') {
                $input[$k] = 'NULL';
            }
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

        // Stamp the sign-off the first time a drill is set to "approved".
        if (
            ($input['status'] ?? null) === 'approved'
            && (int) ($this->fields['approved_by'] ?? 0) === 0
            && empty($input['approved_by'])
        ) {
            $input['approved_by']   = (int) ($_SESSION['glpiID'] ?? 0);
            $input['approval_date'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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

    /**
     * Purge the child records when a drill is permanently deleted. (Soft-delete
     * keeps them so a restore is lossless.) `Drill_Item` rows are handled by the
     * `item_purge` hook wired in setup.php.
     */
    public function cleanDBonPurge()
    {
        foreach ([Objective::class, Action::class] as $child) {
            (new $child())->deleteByCriteria(['plugin_drillreport_drills_id' => $this->fields['id']], true);
        }
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
     * Twig form — GLPI 11's generic form scaffolding (components/form/header,
     * fields_macros, buttons, dates), the same building blocks core itemtype
     * forms use (see templates/drill.html.twig). Child records (Objective /
     * Action / Drill_Item) render as their own tabs, unaffected by this.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@drillreport/drill.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'result_labels' => self::resultLabels(),
            'status_labels' => self::statusLabels(),
            'report_url'    => !$this->isNewID($ID)
                ? (Html::getPrefixedUrl('/plugins/drillreport/front/report.php') . '?id=' . (int) $ID)
                : null,
        ]);

        return true;
    }
}
