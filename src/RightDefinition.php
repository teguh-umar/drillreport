<?php

namespace GlpiPlugin\Drillreport;

/**
 * Single source of truth for the plugin's four profile rights.
 *
 * | right                       | mask             | meaning                              |
 * |-----------------------------|------------------|-------------------------------------|
 * | plugin_drillreport_drill    | ALLSTANDARDRIGHT | drill report + objectives + items   |
 * | plugin_drillreport_action   | ALLSTANDARDRIGHT | improvement actions + "Send to RR"  |
 * | plugin_drillreport_approve  | UPDATE           | sign-off (status -> approved)        |
 * | plugin_drillreport_config   | READ | UPDATE    | Setup page (config, dropdowns)       |
 *
 * READ/UPDATE/.../ALLSTANDARDRIGHT come from GLPI's src/autoload/constants.php,
 * loaded long before any plugin class.
 */
class RightDefinition
{
    /** right name => allowed mask */
    public const ALL = [
        'plugin_drillreport_drill'   => ALLSTANDARDRIGHT,
        'plugin_drillreport_action'  => ALLSTANDARDRIGHT,
        'plugin_drillreport_approve' => UPDATE,
        'plugin_drillreport_config'  => READ | UPDATE,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * Column labels for a right, for Profile::displayRightsChoiceMatrix.
     *
     * @return array<int, string>
     */
    public static function rightsMatrix(string $name): array
    {
        $standard = [
            READ   => __('Read'),
            UPDATE => __('Update'),
            CREATE => __('Create'),
            DELETE => __('Delete'),
            PURGE  => __('Delete permanently'),
        ];

        return match ($name) {
            'plugin_drillreport_drill', 'plugin_drillreport_action' => $standard,
            'plugin_drillreport_approve' => [
                UPDATE => __('Approve / sign off drill reports', 'drillreport'),
            ],
            'plugin_drillreport_config' => [
                READ   => __('Read'),
                UPDATE => __('Update'),
            ],
            default => [],
        };
    }
}
