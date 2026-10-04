<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * Install-time seed data. Idempotent — skips values that already exist, so it is
 * safe to re-run on a version bump. Seeded values are ordinary dropdown rows —
 * an admin can rename, deactivate or delete them, and add their own.
 */
class Seed
{
    /**
     * Starter categories for improvement actions (ISO 22398 / NIST SP 800-84
     * Improvement Plan). Common BCP/DR taxonomy — not a standard, just a head start.
     */
    public const ACTION_CATEGORIES = [
        'Runbook / procedure',
        'Backup & recovery',
        'Infrastructure / capacity',
        'Network & connectivity',
        'Data integrity',
        'Staffing & training',
        'Monitoring & alerting',
        'Third-party / vendor',
        'Documentation',
    ];

    public static function run(): void
    {
        // Test types are global (no entity). Action categories are shared
        // defaults: root entity, visible from every child entity.
        self::seed(new TestType(), TestType::SEED, []);
        self::seed(new ActionCategory(), self::ACTION_CATEGORIES, ['entities_id' => 0, 'is_recursive' => 1]);
        // Scenario is intentionally left empty — it is entirely org-specific.
    }

    /**
     * @param list<string>      $names
     * @param array<string,int> $scope entity columns of the seeded rows; also
     *                                 limits the "already there" check to them
     */
    private static function seed(CommonDropdown $dropdown, array $names, array $scope): void
    {
        $where = array_intersect_key($scope, ['entities_id' => 0]);
        foreach ($names as $name) {
            if ($dropdown->find(['name' => $name] + $where) === []) {
                $dropdown->add(['name' => $name] + $scope);
            }
        }
    }

    /**
     * Wire the two cron-raised events ({@see Cron}) to a default GLPI
     * Notification + NotificationTemplate each, so `raiseEvent()` has
     * something to send instead of silently no-op'ing. Recipient defaults to
     * the entity administrator (a real GLPI concept, never guessed by this
     * plugin) — an admin can add a Group under the Notification's Recipients
     * tab, or edit/disable the whole thing; once created these rows are never
     * touched again by this method.
     */
    public static function seedNotifications(): void
    {
        self::seedNotification(
            Drill::class,
            'reminder',
            __('DR Drill Report — annual reminder', 'drillreport'),
            '[DR Drill Report] ' . __('Drill not submitted yet', 'drillreport') . ' — ##drill.year## — ##drill.entity##',
            __('No DR drill is submitted/approved yet for ##drill.entity## in ##drill.year##.', 'drillreport') . "\n\n"
                . __('Most recent drill on record: ##drill.last_name## (##drill.last_date##).', 'drillreport') . "\n\n"
                . __('Please schedule and log this year\'s drill: ##drill.url##', 'drillreport')
        );

        self::seedNotification(
            Action::class,
            'overdue',
            __('DR Drill Report — overdue improvement actions', 'drillreport'),
            '[DR Drill Report] ##action.count## ' . __('overdue improvement action(s)', 'drillreport') . ' — ##action.entity##',
            __('##action.count## improvement action(s) from a DR drill are past their due date in ##action.entity##.', 'drillreport') . "\n\n"
                . __('Open ##action.list_url## for details and follow-up.', 'drillreport')
        );
    }

    /**
     * @param class-string<\CommonDBTM> $itemtype
     */
    private static function seedNotification(string $itemtype, string $event, string $name, string $subject, string $bodyText): void
    {
        // Never touch it again once it exists — an admin may have edited or
        // deliberately disabled it.
        if ((new \Notification())->find(['itemtype' => $itemtype, 'event' => $event]) !== []) {
            return;
        }

        $tpl = new \NotificationTemplate();
        $tplId = (int) $tpl->add([
            'name'     => $name,
            'itemtype' => $itemtype,
            'comment'  => __('Seeded by drillreport — edit freely, it will not be overwritten.', 'drillreport'),
        ]);
        if ($tplId <= 0) {
            return;
        }

        (new \NotificationTemplateTranslation())->add([
            'notificationtemplates_id' => $tplId,
            'language'                 => '',
            'subject'                  => $subject,
            'content_text'             => $bodyText,
            'content_html'             => '<p>' . nl2br(htmlescape($bodyText)) . '</p>',
        ]);

        $notif = new \Notification();
        $notifId = (int) $notif->add([
            'name'         => $name,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => $itemtype,
            'event'        => $event,
            'is_active'    => 1,
            'comment'      => __('Seeded by drillreport. Add a Group under Recipients if you want the DR team notified directly — this defaults to the entity administrator.', 'drillreport'),
        ]);
        if ($notifId <= 0) {
            return;
        }

        (new \Notification_NotificationTemplate())->add([
            'notifications_id'         => $notifId,
            'mode'                     => \Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $tplId,
        ]);

        (new \NotificationTarget())->add([
            'notifications_id' => $notifId,
            'type'              => \Notification::USER_TYPE,
            'items_id'          => \Notification::ENTITY_ADMINISTRATOR,
        ]);
    }
}
