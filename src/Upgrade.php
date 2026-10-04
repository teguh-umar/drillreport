<?php

namespace GlpiPlugin\Drillreport;

use Toolbox;

/**
 * One-time data steps of an upgrade, called from plugin_drillreport_install()
 * (hook.php) right after the schema change they belong to. Each one runs only
 * in the install run that adds its column, except
 * {@see uniqueNamePerEntity()}, which is safe to re-run.
 */
class Upgrade
{
    /**
     * v1.0.0: scenarios become per entity. A scenario goes to the entity of
     * the drills that use it; one used in several entities stays with the
     * first and gets a copy in each of the others (their drills are pointed
     * at the copy); an unused one stays in the root entity. None is shared
     * with child entities.
     */
    public static function scenariosToEntities(): void
    {
        global $DB;

        $table  = Scenario::getTable();
        $drills = Drill::getTable();
        $fk     = 'plugin_drillreport_scenarios_id';

        foreach ($DB->request(['FROM' => $table]) as $scenario) {
            $entities = [];
            foreach ($DB->request([
                'SELECT'   => 'entities_id',
                'DISTINCT' => true,
                'FROM'     => $drills,
                'WHERE'    => [$fk => (int) $scenario['id']],
                'ORDER'    => 'entities_id',
            ]) as $r) {
                $entities[] = (int) $r['entities_id'];
            }
            if ($entities === []) {
                continue; // unused: root entity, the column's default
            }

            $DB->update($table, ['entities_id' => array_shift($entities), 'is_recursive' => 0], ['id' => (int) $scenario['id']]);

            foreach ($entities as $entity) {
                $DB->insert($table, [
                    'name'          => $scenario['name'],
                    'comment'       => $scenario['comment'],
                    'entities_id'   => $entity,
                    'is_recursive'  => 0,
                    'date_creation' => $scenario['date_creation'],
                    'date_mod'      => $scenario['date_mod'],
                ]);
                $DB->update($drills, [$fk => (int) $DB->insertId()], [$fk => (int) $scenario['id'], 'entities_id' => $entity]);
                self::log(sprintf(
                    'scenario %d "%s" was used in several entities: copied to entity %d',
                    (int) $scenario['id'],
                    (string) $scenario['name'],
                    $entity
                ));
            }
        }
    }

    /**
     * v1.0.0: the existing action categories become shared defaults: root
     * entity, visible from every child entity. Nothing changes for users.
     */
    public static function categoriesToRoot(): void
    {
        global $DB;

        $DB->update(ActionCategory::getTable(), ['entities_id' => 0, 'is_recursive' => 1], ['id' => ['>', 0]]);
    }

    /**
     * v1.0.0: store the name and entity of every asset already linked to a
     * drill. An asset whose class cannot be loaded here is left empty and
     * filled the first time the link is shown ({@see Drill_Item::assetView()}).
     */
    public static function snapshotAssets(): void
    {
        global $DB;

        $table = Drill_Item::getTable();
        foreach ($DB->request(['SELECT' => ['id', 'itemtype', 'items_id'], 'FROM' => $table]) as $r) {
            try {
                $asset = getItemForItemtype((string) $r['itemtype']);
                if (!$asset || !$asset->getFromDB((int) $r['items_id'])) {
                    continue;
                }
                $DB->update($table, [
                    'asset_name'        => (string) $asset->getName(),
                    'asset_entities_id' => $asset->isEntityAssign() ? (int) $asset->getEntityID() : 0,
                ], ['id' => (int) $r['id']]);
            } catch (\Throwable $e) {
                self::log('asset snapshot skipped for link ' . (int) $r['id'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * v1.0.0: a scenario / category name is unique within its entity. The key
     * is not added while duplicates exist (it would fail); they are logged
     * so an admin can rename or merge them, and the next install run adds it.
     */
    public static function uniqueNamePerEntity(\Migration $migration): void
    {
        global $DB;

        foreach ([Scenario::getTable(), ActionCategory::getTable()] as $table) {
            if (!$DB->fieldExists($table, 'entities_id')) {
                continue;
            }
            $seen       = [];
            $duplicates = [];
            foreach ($DB->request(['SELECT' => ['entities_id', 'name'], 'FROM' => $table]) as $r) {
                // The key compares names the way the column's collation does:
                // ignoring case and trailing spaces.
                $key = (int) $r['entities_id'] . '|' . mb_strtolower(rtrim((string) $r['name']));
                if (isset($seen[$key])) {
                    $duplicates[$key] = sprintf('"%s" (entity %d)', (string) $r['name'], (int) $r['entities_id']);
                }
                $seen[$key] = true;
            }
            if ($duplicates !== []) {
                self::log("$table: unique key not added, duplicate names: " . implode(', ', $duplicates));
                continue;
            }
            try {
                $migration->addKey($table, ['entities_id', 'name'], 'unicity', 'UNIQUE');
                $migration->migrationOneTable($table);
            } catch (\Throwable $e) {
                // e.g. two names the collation treats as equal (accents).
                self::log("$table: unique key not added: " . $e->getMessage());
            }
        }
    }

    private static function log(string $message): void
    {
        Toolbox::logInFile('drillreport_install', $message . "\n", true);
    }
}
