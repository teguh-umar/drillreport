<?php

namespace GlpiPlugin\Drillreport;

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
     * Annual "drill not submitted" reminder.
     *
     * @return int >0 = reminders raised, 0 = nothing to do, <0 = error
     */
    public static function cronDrillReminder(\CronTask $task): int
    {
        try {
            if (!(bool) Config::get('reminder_enabled')) {
                return 0;
            }
            if ((int) date('n') !== (int) Config::get('reminder_month')) {
                return 0;
            }

            $year = (int) date('Y');

            // A drill counts as "done for this year" once it is submitted/approved
            // with a date_drill in the current year.
            $done = countElementsInTable(Drill::getTable(), [
                'is_deleted' => 0,
                'status'     => ['submitted', 'approved'],
                'RAW'        => ['YEAR(`date_drill`)' => $year],
            ]);
            if ($done > 0) {
                return 0;
            }

            // TODO(mvp): raise a GLPI notification to the DR group(s).
            // Skeleton stage: log only.
            Toolbox::logInFile(
                'drillreport_cron',
                sprintf("DrillReminder: no submitted drill for %d\n", $year)
            );
            $task->addVolume(1);
            return 1;
        } catch (\Throwable $e) {
            Toolbox::logInFile('drillreport_cron', 'cronDrillReminder error: ' . $e->getMessage() . "\n", true);
            return -1;
        }
    }

    /**
     * Weekly overdue-improvement-action notice.
     *
     * @return int
     */
    public static function cronActionFollowup(\CronTask $task): int
    {
        try {
            $today = date('Y-m-d');

            $overdue = countElementsInTable(Action::getTable(), [
                'date_due' => ['<', $today],
                'status'   => ['open', 'in_progress'],
                'NOT'      => ['date_due' => null],
            ]);
            if ($overdue === 0) {
                return 0;
            }

            // TODO(mvp): notify each action's owner group / user.
            Toolbox::logInFile(
                'drillreport_cron',
                sprintf("ActionFollowup: %d overdue action(s)\n", $overdue)
            );
            $task->addVolume($overdue);
            return $overdue;
        } catch (\Throwable $e) {
            Toolbox::logInFile('drillreport_cron', 'cronActionFollowup error: ' . $e->getMessage() . "\n", true);
            return -1;
        }
    }
}
