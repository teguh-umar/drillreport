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
        self::seed(new TestType(), TestType::SEED);
        self::seed(new ActionCategory(), self::ACTION_CATEGORIES);
        // Scenario is intentionally left empty — it is entirely org-specific.
    }

    /**
     * @param list<string> $names
     */
    private static function seed(CommonDropdown $dropdown, array $names): void
    {
        foreach ($names as $name) {
            if ($dropdown->find(['name' => $name]) === []) {
                $dropdown->add(['name' => $name, 'entities_id' => 0]);
            }
        }
    }
}
