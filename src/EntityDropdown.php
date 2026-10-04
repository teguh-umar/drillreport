<?php

namespace GlpiPlugin\Drillreport;

use Session;

/**
 * Shared by the entity-aware reference dropdowns ({@see Scenario},
 * {@see ActionCategory}): a row belongs to an entity and may be shared with
 * its child entities (`entities_id` + `is_recursive`).
 *
 * A drill or an action may only use a row visible from the drill's entity,
 * the GLPI way: same entity, or a recursive row of an ancestor entity.
 */
trait EntityDropdown
{
    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        return is_array($input) && $this->isDuplicateName($input) ? false : $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return is_array($input) && $this->isDuplicateName($input) ? false : $input;
    }

    /**
     * A name is unique per entity (the table's `unicity` key). Refuse a
     * duplicate with a message instead of letting the key fail the query.
     *
     * @param array<string,mixed> $input
     */
    private function isDuplicateName(array $input): bool
    {
        $name = trim((string) ($input['name'] ?? $this->fields['name'] ?? ''));
        if ($name === '') {
            return false;
        }
        $entity = (int) ($input['entities_id'] ?? $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

        $where = ['name' => $name, 'entities_id' => $entity];
        $id    = (int) ($input['id'] ?? $this->fields['id'] ?? 0);
        if ($id > 0) {
            $where[] = ['NOT' => ['id' => $id]];
        }
        if (countElementsInTable(static::getTable(), $where) === 0) {
            return false;
        }

        Session::addMessageAfterRedirect(
            sprintf(__s('"%s" already exists in this entity.', 'drillreport'), htmlescape($name)),
            false,
            ERROR
        );
        return true;
    }

    /** Can a drill of entity $entities_id use row $id? */
    public static function visibleFrom(int $id, int $entities_id): bool
    {
        $row = new static();
        return $id > 0 && $row->getFromDB($id) && $row->isAccessibleFromEntities([$entities_id]);
    }

    /**
     * Id of the row named $name that entity $entities_id can use: its own row
     * first, then a recursive row of an ancestor. 0 when there is none.
     */
    public static function idByName(string $name, int $entities_id): int
    {
        global $DB;

        $table    = static::getTable();
        $ancestor = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => $table,
            'WHERE'  => ['name' => $name] + getEntitiesRestrictCriteria($table, '', $entities_id, true),
            'ORDER'  => 'id',
        ]) as $r) {
            if ((int) $r['entities_id'] === $entities_id) {
                return (int) $r['id'];
            }
            $ancestor = $ancestor ?: (int) $r['id'];
        }
        return $ancestor;
    }

    /**
     * The row to use in entity $to in place of row $id: the row itself when
     * it is visible from there, else a row of the same name that is, else a
     * copy created in $to (the name only). 0 when $id is no row at all.
     */
    public static function forEntity(int $id, int $to): int
    {
        $row = new static();
        if ($id <= 0 || !$row->getFromDB($id)) {
            return 0;
        }
        if ($row->isAccessibleFromEntities([$to])) {
            return $id;
        }

        $name = (string) $row->fields['name'];
        $hit  = static::idByName($name, $to);
        if ($hit > 0) {
            return $hit;
        }

        $new = (new static())->add(['name' => $name, 'entities_id' => $to, 'is_recursive' => 0]);
        return $new ? (int) $new : 0;
    }
}
