<?php

namespace GlpiPlugin\Drillreport;

use Dropdown;

/**
 * Assembles the printable drill report (ISO 22398 / NIST SP 800-84 AAR/IP —
 * docs/drillreport-konsep-awal.md §6). Rendered by front/report.php via
 * templates/report.html.twig.
 *
 * All values returned here are plain scalars / arrays emitted through Twig
 * autoescaping — nothing is pre-rendered HTML.
 */
class Report
{
    /** Report sections, in order. Keys match the `report_sections` config CSV. */
    public const SECTIONS = [
        'summary', 'overview', 'scope', 'timeline', 'rto_rpo',
        'objectives', 'findings', 'remediation', 'approval',
    ];

    /** Severity display order for the remediation summary. */
    private const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    /** @return array<string,string> section key => English title */
    private static function sectionTitles(): array
    {
        return [
            'summary'     => __('Executive Summary', 'drillreport'),
            'overview'    => __('Scenario Overview', 'drillreport'),
            'scope'       => __('Systems in Scope', 'drillreport'),
            'timeline'    => __('Timeline', 'drillreport'),
            'rto_rpo'     => __('RTO / RPO — Target vs Actual', 'drillreport'),
            'objectives'  => __('Objectives Assessment', 'drillreport'),
            'findings'    => __('Findings & Improvement Plan', 'drillreport'),
            'remediation' => __('Remediation Summary', 'drillreport'),
            'approval'    => __('Approval', 'drillreport'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function build(Drill $drill): array
    {
        global $DB;

        $f   = $drill->fields;
        $eid = (int) $f['entities_id'];

        $enabled = array_values(array_intersect(
            self::SECTIONS,
            array_map('trim', explode(',', (string) Config::get('report_sections')))
        )) ?: self::SECTIONS;

        // Numbered headings for the enabled sections, in order.
        $titles      = self::sectionTitles();
        $headings    = [];
        $sectionNum  = 0;
        foreach ($enabled as $key) {
            $headings[$key] = (++$sectionNum) . '. ' . ($titles[$key] ?? ucfirst($key));
        }

        $showCriticity = (bool) Config::get('show_business_criticity');
        $showTimeline  = (bool) Config::get('show_timeline_fields');

        // ---- objectives / activities -------------------------------
        $objectives  = [];
        $objTarget   = 0;
        $objActual   = 0;
        foreach ($DB->request([
            'FROM'  => Objective::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drill->getID()],
            'ORDER' => ['start_time', 'id'],
        ]) as $r) {
            $target = (int) ($r['target_minutes'] ?? 0);
            $actual = Objective::minutesBetween($r['start_time'] ?? null, $r['end_time'] ?? null);
            $objTarget += $target;
            $objActual += (int) ($actual ?? 0);
            $objectives[] = [
                'name'       => (string) $r['name'],
                'content'    => (string) $r['content'],
                'result'     => Objective::resultLabels()[$r['result']] ?? $r['result'],
                'result_key' => (string) $r['result'],
                'is_met'     => $r['result'] === 'met',
                'target'     => $target > 0 ? $target : null,
                'actual'     => $actual,
                'start_time' => (string) ($r['start_time'] ?? ''),
                'end_time'   => (string) ($r['end_time'] ?? ''),
                'comment'    => (string) $r['comment'],
            ];
        }

        // ---- systems in scope + per-asset RTO/RPO -------------------
        $items = [];
        foreach ($DB->request([
            'FROM'  => Drill_Item::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drill->getID()],
            'ORDER' => ['recovery_order', 'id'],
        ]) as $r) {
            $assetName = NOT_AVAILABLE;
            $criticity = '';
            $asset = getItemForItemtype((string) $r['itemtype']);
            if ($asset && $asset->getFromDB((int) $r['items_id'])) {
                $assetName = $asset->getName();
                if ($showCriticity && isset($asset->fields['businesscriticities_id'])) {
                    $criticity = (string) Dropdown::getDropdownName(
                        'glpi_businesscriticities',
                        (int) $asset->fields['businesscriticities_id']
                    );
                    if ($criticity === '&nbsp;') {
                        $criticity = '';
                    }
                }
            }

            $rtoTarget = $r['rto_target_minutes'];
            $rtoActual = $r['rto_actual_minutes'];
            $items[] = [
                'itemtype'   => (string) $r['itemtype'],
                'type_name'  => is_a($r['itemtype'], \CommonDBTM::class, true) ? $r['itemtype']::getTypeName(1) : $r['itemtype'],
                'name'       => $assetName,
                'criticity'  => $criticity,
                'rto_target' => $rtoTarget,
                'rto_actual' => $rtoActual,
                'rpo_target' => $r['rpo_target_minutes'],
                'rpo_actual' => $r['rpo_actual_minutes'],
                'rto_met'    => ($rtoTarget !== null && $rtoActual !== null) ? ((int) $rtoActual <= (int) $rtoTarget) : null,
                'recovered'  => (int) $r['is_recovered'] === 1,
                'order'      => $r['recovery_order'] !== null ? (int) $r['recovery_order'] : null,
                'comment'    => (string) $r['comment'],
            ];
        }

        // ---- findings / improvement plan ---------------------------
        $findings = [];
        foreach ($DB->request([
            'FROM'  => Action::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drill->getID()],
        ]) as $r) {
            $owner = '';
            if ((int) $r['groups_id'] > 0) {
                $owner = (string) Dropdown::getDropdownName('glpi_groups', (int) $r['groups_id']);
            } elseif ((int) $r['users_id'] > 0) {
                $owner = (string) \getUserName((int) $r['users_id']);
            }
            $sevKey = (string) $r['severity'];
            $findings[] = [
                'name'            => (string) $r['name'],
                'category'        => (int) $r['plugin_drillreport_actioncategories_id'] > 0
                    ? (string) Dropdown::getDropdownName(ActionCategory::getTable(), (int) $r['plugin_drillreport_actioncategories_id'])
                    : '',
                'content'         => (string) $r['content'],
                'cause'           => (string) $r['cause'],
                'recommendation'  => (string) $r['recommendation'],
                'recommendations' => self::bulletize((string) $r['recommendation']),
                'severity'        => Action::severityLabels()[$sevKey] ?? $sevKey,
                'severity_key'    => $sevKey,
                'severity_rank'   => self::SEVERITY_ORDER[$sevKey] ?? 9,
                'status'          => Action::statusLabels()[$r['status']] ?? $r['status'],
                'owner'           => $owner,
                'date_due'        => (string) $r['date_due'],
                'rr_code'         => (int) $r['riskregister_findings_id'] > 0 ? (string) $r['riskregister_code'] : '',
            ];
        }
        usort($findings, static fn ($a, $b) => [$a['severity_rank'], $a['name']] <=> [$b['severity_rank'], $b['name']]);

        // ---- evidence (attached documents) ------------------------
        $evidence = [];
        foreach ($DB->request([
            'SELECT' => ['d.id', 'd.name', 'd.filename'],
            'FROM'   => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'  => ['di.itemtype' => Drill::class, 'di.items_id' => $drill->getID()],
        ]) as $r) {
            $evidence[] = ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?: $r['filename'])];
        }

        // ---- overall RTO/RPO -------------------------------------
        // Drill-level fields win; when empty, fall back to the sum of the
        // objectives' target / actual durations (docs §6.6 mapping rule).
        $rtoTarget = $f['rto_target_minutes'] !== null ? (int) $f['rto_target_minutes'] : ($objTarget ?: null);
        $rtoActual = $f['rto_actual_minutes'] !== null ? (int) $f['rto_actual_minutes'] : ($objActual ?: null);
        $rtoFromActivities = ($f['rto_target_minutes'] === null && $objTarget > 0)
            || ($f['rto_actual_minutes'] === null && $objActual > 0);

        $rtoMet = ($rtoTarget !== null && $rtoActual !== null) ? ($rtoActual <= $rtoTarget) : null;
        $rpoMet = ($f['rpo_target_minutes'] !== null && $f['rpo_actual_minutes'] !== null)
            ? ((int) $f['rpo_actual_minutes'] <= (int) $f['rpo_target_minutes']) : null;

        // ---- timeline + computed duration -----------------------
        $decl = (string) $f['declaration_time'];
        $recv = (string) $f['recovery_time'];
        $vald = (string) $f['validation_time'];
        $durationMin = null;
        if ($decl !== '' && $recv !== '' && strtotime($recv) !== false && strtotime($decl) !== false) {
            $durationMin = (int) round((strtotime($recv) - strtotime($decl)) / 60);
        }

        $name    = (string) $f['name'];
        $isSample = str_starts_with($name, Sample::MARKER);

        return [
            'sections'       => $enabled,
            'headings'       => $headings,
            'show_timeline'  => $showTimeline,
            'show_criticity' => $showCriticity,
            'has_timeline'   => ($decl !== '' || $recv !== '' || $vald !== ''),
            'drill' => [
                'id'                => $drill->getID(),
                'name'              => $name,
                'name_clean'        => $isSample ? trim(substr($name, strlen(Sample::MARKER))) : $name,
                'is_sample'         => $isSample,
                'entity'            => (string) Dropdown::getDropdownName('glpi_entities', $eid),
                'date_drill'        => (string) $f['date_drill'],
                'scenario'          => (string) Dropdown::getDropdownName(Scenario::getTable(), (int) $f['plugin_drillreport_scenarios_id']),
                'test_type'         => (string) Dropdown::getDropdownName(TestType::getTable(), (int) $f['plugin_drillreport_testtypes_id']),
                'group_in_charge'   => (int) $f['groups_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id']) : '',
                'coordinator'       => (int) $f['users_id_tech'] > 0 ? (string) \getUserName((int) $f['users_id_tech']) : '',
                'objectives_text'   => (string) $f['objectives'],
                'scope_summary'     => (string) $f['scope_summary'],
                'declaration_time'  => $decl,
                'recovery_time'     => $recv,
                'validation_time'   => $vald,
                'duration_minutes'  => $durationMin,
                'rto_target'        => $rtoTarget,
                'rto_actual'        => $rtoActual,
                'rto_met'           => $rtoMet,
                'rto_from_activities' => $rtoFromActivities,
                'obj_target_total'  => $objTarget ?: null,
                'obj_actual_total'  => $objActual ?: null,
                'rpo_target'        => $f['rpo_target_minutes'],
                'rpo_actual'        => $f['rpo_actual_minutes'],
                'rpo_met'           => $rpoMet,
                'data_loss_summary' => (string) $f['data_loss_summary'],
                'overall_result'    => Drill::resultLabels()[$f['overall_result']] ?? $f['overall_result'],
                'result_key'        => (string) $f['overall_result'],
                'executive_summary' => (string) $f['executive_summary'],
                'lessons_learned'   => (string) $f['lessons_learned'],
                'status'            => Drill::statusLabels()[$f['status']] ?? $f['status'],
                'approved_by'       => (int) $f['approved_by'] > 0 ? (string) \getUserName((int) $f['approved_by']) : '',
                'approval_date'     => (string) $f['approval_date'],
            ],
            'objectives'         => $objectives,
            'has_objective_time' => ($objTarget > 0 || $objActual > 0),
            'items'              => $items,
            'findings'           => $findings,
            'evidence'           => $evidence,
        ];
    }

    /**
     * Split an enumerated recommendation ("1) … 2) … 3) …" or "1. … 2. …" or
     * bullet-per-line) into a list. Returns a single-element list when there is
     * no clear enumeration.
     *
     * @return list<string>
     */
    private static function bulletize(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // newline-separated bullets
        $lines = preg_split('/\R+/', $text) ?: [];
        if (count($lines) > 1) {
            return array_values(array_filter(array_map(
                static fn ($l) => trim(preg_replace('/^\s*(?:\d+[\).\-]|[-*•])\s*/u', '', $l) ?? $l),
                $lines
            ), static fn ($l) => $l !== ''));
        }

        // inline "1) ... 2) ... 3) ..." — split before "<space>N)" / "<space>N."
        $parts = preg_split('/(?<=\D)\s+(?=\d+[\)\.]\s)/u', $text) ?: [$text];
        if (count($parts) > 1) {
            return array_values(array_filter(array_map(
                static fn ($p) => trim(preg_replace('/^\s*\d+[\)\.]\s*/', '', $p) ?? $p),
                $parts
            ), static fn ($p) => $p !== ''));
        }

        return [$text];
    }
}
