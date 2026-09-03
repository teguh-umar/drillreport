<?php

namespace GlpiPlugin\Drillreport;

/**
 * Thin collector for the plugin's table DDL.
 *
 * plugin_drillreport_install() (hook.php) calls
 *   Migration::register('glpi_plugin_drillreport_xxx', "CREATE TABLE ...")
 * for every table, then Migration::install(). install() creates any registered
 * table that does not yet exist; uninstall() discovers this plugin's tables by
 * name prefix and drops them all.
 */
class Migration
{
    /** @var array<string, string> table name => "CREATE TABLE" SQL */
    private static array $tables = [];

    public static function register(string $table, string $createSql): void
    {
        self::$tables[$table] = $createSql;
    }

    public static function install(\Migration $migration): void
    {
        global $DB;

        foreach (self::$tables as $table => $sql) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery($sql);
            }
        }

        $migration->executeMigration();
    }

    /**
     * Drop every table owned by this plugin. Self-sufficient — GLPI never runs
     * the install hook on the uninstall path, so discover tables at runtime by
     * their name prefix.
     */
    public static function uninstall(): void
    {
        global $DB;

        $tables = [];
        foreach ($DB->listTables('glpi\_plugin\_drillreport\_%') as $row) {
            $tables[] = $row['TABLE_NAME'];
        }

        // Longest name first so child / link tables drop before their parents.
        usort($tables, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($tables as $t) {
            $DB->doQuery('DROP TABLE IF EXISTS ' . $DB->quoteName($t));
        }
    }

    /** @return array<int, string> */
    public static function tables(): array
    {
        return array_keys(self::$tables);
    }
}
