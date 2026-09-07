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
        'summary', 'overview', 'participants', 'scope', 'timeline',
        'rto_rpo', 'objectives', 'findings', 'evidence', 'approval',
    ];

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

        $showCriticity = (bool) Config::get('show_business_criticity');
        $showTimeline  = (bool) Config::get('show_timeline_fields');

        // ---- objectives ------------------------------------------------
        $objectives = [];
        foreach ($DB->request([
            'FROM'  => Objective::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drill->getID()],
            'ORDER' => 'id',
        ]) as $r) {
            $objectives[] = [
                'name'    => (string) $r['name'],
                'content' => (string) $r['content'],
                'result'  => Objective::resultLabels()[$r['result']] ?? $r['result'],
                'is_met'  => $r['result'] === 'met',
                'comment' => (string) $r['comment'],
            ];
        }

        // ---- systems in scope + per-asset RTO/RPO --------------------
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
                'order'      => $r['recovery_order'],
                'comment'    => (string) $r['comment'],
            ];
        }

        // ---- findings / improvement plan ----------------------------
        $findings = [];
        foreach ($DB->request([
            'FROM'  => Action::getTable(),
            'WHERE' => ['plugin_drillreport_drills_id' => $drill->getID()],
            'ORDER' => ['severity', 'id'],
        ]) as $r) {
            $owner = '';
            if ((int) $r['groups_id'] > 0) {
                $owner = (string) Dropdown::getDropdownName('glpi_groups', (int) $r['groups_id']);
            } elseif ((int) $r['users_id'] > 0) {
                $owner = (string) \getUserName((int) $r['users_id']);
            }
            $findings[] = [
                'name'           => (string) $r['name'],
                'content'        => (string) $r['content'],
                'cause'          => (string) $r['cause'],
                'recommendation' => (string) $r['recommendation'],
                'severity'       => Action::severityLabels()[$r['severity']] ?? $r['severity'],
                'severity_key'   => (string) $r['severity'],
                'status'         => Action::statusLabels()[$r['status']] ?? $r['status'],
                'owner'          => $owner,
                'date_due'       => (string) $r['date_due'],
                'rr_code'        => (int) $r['riskregister_findings_id'] > 0 ? (string) $r['riskregister_code'] : '',
            ];
        }

        // ---- evidence (attached documents) -------------------------
        $evidence = [];
        foreach ($DB->request([
            'SELECT' => ['d.id', 'd.name', 'd.filename'],
            'FROM'   => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'  => ['di.itemtype' => Drill::class, 'di.items_id' => $drill->getID()],
        ]) as $r) {
            $evidence[] = ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?: $r['filename'])];
        }

        // ---- overall RTO/RPO at drill level ------------------------
        $rtoMet = ($f['rto_target_minutes'] !== null && $f['rto_actual_minutes'] !== null)
            ? ((int) $f['rto_actual_minutes'] <= (int) $f['rto_target_minutes']) : null;
        $rpoMet = ($f['rpo_target_minutes'] !== null && $f['rpo_actual_minutes'] !== null)
            ? ((int) $f['rpo_actual_minutes'] <= (int) $f['rpo_target_minutes']) : null;

        return [
            'sections'       => $enabled,
            'show_timeline'  => $showTimeline,
            'show_criticity' => $showCriticity,
            'drill' => [
                'id'                => $drill->getID(),
                'name'              => (string) $f['name'],
                'entity'            => (string) Dropdown::getDropdownName('glpi_entities', $eid),
                'date_drill'        => (string) $f['date_drill'],
                'scenario'          => (string) Dropdown::getDropdownName(Scenario::getTable(), (int) $f['plugin_drillreport_scenarios_id']),
                'test_type'         => (string) Dropdown::getDropdownName(TestType::getTable(), (int) $f['plugin_drillreport_testtypes_id']),
                'group_in_charge'   => (int) $f['groups_id'] > 0 ? (string) Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id']) : '',
                'coordinator'       => (int) $f['users_id_tech'] > 0 ? (string) \getUserName((int) $f['users_id_tech']) : '',
                'objectives_text'   => (string) $f['objectives'],
                'scope_summary'     => (string) $f['scope_summary'],
                'declaration_time'  => (string) $f['declaration_time'],
                'recovery_time'     => (string) $f['recovery_time'],
                'validation_time'   => (string) $f['validation_time'],
                'rto_target'        => $f['rto_target_minutes'],
                'rto_actual'        => $f['rto_actual_minutes'],
                'rto_met'           => $rtoMet,
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
            'objectives' => $objectives,
            'items'      => $items,
            'findings'   => $findings,
            'evidence'   => $evidence,
        ];
    }
}
