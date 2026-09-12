<?php

namespace GlpiPlugin\Drillreport;

use NotificationEvent;
use Toolbox;

/**
 * Automatic-action host for DR Drill Report.
 *
 * GLPI 11's {@see \CronTask::launch()} dispatches a task by building
 * `sprintf('%s::cron%s', itemtype, name)` and calling it if `is_callable`, and
 * reads metadata via `[$itemtype, 'cronInfo']`. `CronTask::register()` only needs
 * `class_exists($itemtype)`, so this plain class is a valid itemtype.
 *
 * Tasks (registered in {@see plugin_drillreport_install()}):
 *   - DrillReminder   daily; acts only in the configured reminder_month
 *   - ActionFollowup  weekly; notifies owners of overdue improvement actions
 *
 * Both raise a real GLPI notification event ({@see NotificationEvent::raiseEvent()})
 * against {@see NotificationTargetDrill} / {@see NotificationTargetAction}.
 * `raiseEvent()` is a safe no-op (returns false, does nothing) until an admin
 * configures a matching Notification in Setup > Notifications — see
 * docs/notifications.md for the one-time setup.
 *
 * GLPI cron return contract: >0 = did work, 0 = nothing to do, <0 = error.
 */
class Cron
{
    /**
     * @return array{description: string}|null
     */
    public static function cronInfo(string $name): ?array
    {
        return match ($name) {
            'DrillReminder' => [
                'description' => __('Remind the DR team when this year\'s drill has not been submitted', 'drillreport'),
            ],
            'ActionFollowup' => [
                'description' => __('Notify owners of overdue drill improvement actions', 'drillreport'),
            ],
            default => null,
        };
    }

    /**
     * Annual "drill not submitted" reminder — one notification per entity that
     * has ever run a drill (so the reminder has an entity/DR-group context to
     * target) and has none submitted/approved for the current year.
     *
     * @return int >0 = reminders raised, 0 = nothing to do, <0 = error
     */
    public static function cronDrillReminder(\CronTask $task): int
    {
        global $DB;

        try {
            if (!(bool) Config::get('reminder_enabled')) {
                return 0;
            }
            if ((int) date('n') !== (int) Config::get('reminder_month')) {
                return 0;
            }

            $year = (int) date('Y');

            $entityIds = [];
            foreach ($DB->request([
                'SELECT'   => 'entities_id',
                'DISTINCT' => true,
                'FROM'     => Drill::getTable(),
                'WHERE'    => ['is_deleted' => 0],
            ]) as $row) {
                $entityIds[] = (int) $row['entities_id'];
            }
            if ($entityIds === []) {
                // No drill has ever been logged — nothing to root the reminder in.
                return 0;
            }

            $missing = 0;
            $raised  = 0;
            foreach ($entityIds as $entities_id) {
                $done = countElementsInTable(Drill::getTable(), [
                    'is_deleted'  => 0,
                    'entities_id' => $entities_id,
                    'status'      => ['submitted', 'approved'],
                    'RAW'         => ['YEAR(`date_drill`)' => $year],
                ]);
                if ($done > 0) {
                    continue;
                }
                $missing++;

                $last = $DB->request([
                    'FROM'  => Drill::getTable(),
                    'WHERE' => ['entities_id' => $entities_id, 'is_deleted' => 0],
                    'ORDER' => ['date_drill DESC'],
                    'LIMIT' => 1,
                ])->current();

                $ok = NotificationEvent::raiseEvent('reminder', new Drill(), [
                    'entities_id' => $entities_id,
                    'year'        => $year,
                    'last_name'   => $last['name'] ?? '',
                    'last_date'   => $last['date_drill'] ?? '',
                ]);
                if ($ok) {
                    $raised++;
                    $task->log(sprintf("DrillReminder: raised for entity %d (year %d)\n", $entities_id, $year));
                }
            }

            Toolbox::logInFile(
                'drillreport_cron',
                sprintf(
                    "DrillReminder: %d/%d entit%s missing a %d drill; %d notification(s) raised\n",
                    $missing,
                    count($entityIds),
                    count($entityIds) === 1 ? 'y' : 'ies',
                    $year,
                    $raised
                )
            );
            if ($raised === 0) {
                return 0;
            }
            $task->addVolume($raised);
            return $raised;
        } catch (\Throwable $e) {
            Toolbox::logInFile('drillreport_cron', 'cronDrillReminder error: ' . $e->getMessage() . "\n", true);
            return -1;
        }
    }

    /**
     * Weekly overdue-improvement-action notice — one notification per entity,
     * batching that entity's overdue actions ({@see NotificationTargetAction}).
     *
     * @return int
     */
    public static function cronActionFollowup(\CronTask $task): int
    {
        global $DB;

        try {
            $today = date('Y-m-d');

            $byEntity = [];
            foreach ($DB->request([
                'SELECT'    => [
                    'a.*',
                    'd.entities_id AS entities_id',
                    'd.name AS drill_name',
                ],
                'FROM'      => Action::getTable() . ' AS a',
                'INNER JOIN' => [
                    Drill::getTable() . ' AS d' => ['ON' => ['a' => 'plugin_drillreport_drills_id', 'd' => 'id']],
                ],
                'WHERE'     => [
                    'a.date_due' => ['<', $today],
                    'a.status'   => ['open', 'in_progress'],
                    ['NOT' => ['a.date_due' => null]],
                    'd.is_deleted' => 0,
                ],
            ]) as $row) {
                $byEntity[(int) $row['entities_id']][(int) $row['id']] = $row;
            }

            if ($byEntity === []) {
                return 0;
            }

            $total  = 0;
            $raised = 0;
            foreach ($byEntity as $entities_id => $actions) {
                $ok = NotificationEvent::raiseEvent('overdue', new Action(), [
                    'entities_id' => $entities_id,
                    'items'       => $actions,
                ]);
                $total += count($actions);
                if ($ok) {
                    $raised++;
                    $task->log(sprintf("ActionFollowup: %d overdue action(s) for entity %d\n", count($actions), $entities_id));
                }
            }

            Toolbox::logInFile(
                'drillreport_cron',
                sprintf("ActionFollowup: %d overdue action(s) across %d entit%s; %d notification(s) raised\n", $total, count($byEntity), count($byEntity) === 1 ? 'y' : 'ies', $raised)
            );
            $task->addVolume($total);
            return $total;
        } catch (\Throwable $e) {
            Toolbox::logInFile('drillreport_cron', 'cronActionFollowup error: ' . $e->getMessage() . "\n", true);
            return -1;
        }
    }
}
