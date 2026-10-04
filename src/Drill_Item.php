<?php

namespace GlpiPlugin\Drillreport;

use CommonDBRelation;
use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Html;
use Session;

/**
 * Polymorphic link between a {@see Drill} and a GLPI asset in the drill's scope,
 * carrying the per-asset RTO/RPO target vs actual and recovery outcome
 * (ISO 22398 / NIST 800-84 report components 5, 7).
 *
 * Table: glpi_plugin_drillreport_drills_items
 *   item_1 = the Drill        (plugin_drillreport_drills_id)
 *   item_2 = a polymorphic asset (itemtype / items_id) — Appliance / Computer / ...
 *
 * The link is cleaned when either side is purged (item_purge hook in setup.php).
 */
class Drill_Item extends CommonDBRelation
{
    public static $itemtype_1    = Drill::class;
    public static $items_id_1    = 'plugin_drillreport_drills_id';
    public static $take_entity_1 = false;
    /** Manage scope on any drill the user can READ; write is gated by $rightname. */
    public static $checkItem_1_Rights = self::HAVE_VIEW_RIGHT_ON_ITEM;

    public static $itemtype_2         = 'itemtype';
    public static $items_id_2         = 'items_id';
    public static $take_entity_2      = true;
    public static $checkItem_2_Rights = self::DONT_CHECK_ITEM_RIGHTS;

    public static $rightname = 'plugin_drillreport_drill';

    public static function getTypeName($nb = 0)
    {
        return _n('System in scope', 'Systems in scope', $nb, 'drillreport');
    }

    /**
     * Asset itemtypes a drill may cover. Configurable via
     * `plugin:drillreport` / `asset_itemtypes` — defaults to Appliance + Computer.
     *
     * @return list<string>
     */
    public static function getLinkedAssetItemtypes(): array
    {
        return Config::assetItemtypes();
    }

    public function getForbiddenStandardMassiveAction()
    {
        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }

    /**
     * On link creation, pre-fill the RTO/RPO *targets* from the asset's custom
     * fields (the `fields` plugin), unless the caller already supplied them.
     * Soft — degrades to no-op when `fields` is absent or nothing matches.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|false
     */
    /** Nullable INT columns — an empty form field becomes SQL NULL (MySQL strict mode). */
    private const NULLABLE_FIELDS = [
        'rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'recovery_order',
    ];

    /**
     * Can this asset be linked to this drill? An allowed itemtype, an existing
     * asset, and visible from the drill's entity the GLPI way (same entity, or
     * a recursive asset of an ancestor entity). The form's picker already
     * filters like this; a posted value is checked again here.
     */
    public static function assetLinkable(string $itemtype, int $items_id, int $drills_id): bool
    {
        return self::linkableAsset($itemtype, $items_id, $drills_id) !== null;
    }

    /** The asset when it can be linked to the drill ({@see assetLinkable()}), else null. */
    private static function linkableAsset(string $itemtype, int $items_id, int $drills_id): ?CommonDBTM
    {
        if (!in_array($itemtype, self::getLinkedAssetItemtypes(), true)) {
            return null;
        }
        $asset = getItemForItemtype($itemtype);
        $drill = new Drill();
        if (!$asset || $items_id <= 0 || !$asset->getFromDB($items_id) || !$drill->getFromDB($drills_id)) {
            return null;
        }
        return $asset->isAccessibleFromEntities([(int) $drill->fields['entities_id']]) ? $asset : null;
    }

    // ---------------------------------------------------------------------
    // Asset snapshot (an asset that moved to an entity the drill cannot see)
    // ---------------------------------------------------------------------

    /**
     * Snapshot columns of a link row: the asset's name and the entity it
     * belongs to, as stored values.
     *
     * @return array{asset_name:string, asset_entities_id:int}
     */
    private static function snapshotOf(CommonDBTM $asset): array
    {
        return [
            'asset_name'        => (string) $asset->getName(),
            'asset_entities_id' => $asset->isEntityAssign() ? (int) $asset->getEntityID() : 0,
        ];
    }

    /**
     * `item_update` hook of the linked asset itemtypes (setup.php): keep the
     * snapshot of every link to this asset. The entity is always stored. The
     * name is stored while the drill can still see the asset, and one last
     * time when the asset changes entity; after that the drill keeps the name
     * it knew, not the other entity's live data.
     */
    public static function refreshSnapshot(CommonDBTM $asset): void
    {
        global $DB;

        $updates = is_array($asset->updates ?? null) ? $asset->updates : [];
        if (array_intersect(['entities_id', 'is_recursive', 'name'], $updates) === []) {
            return;
        }
        $moved    = in_array('entities_id', $updates, true);
        $snapshot = self::snapshotOf($asset);

        foreach ($DB->request([
            'SELECT'     => ['i.id', 'd.entities_id AS drill_entity'],
            'FROM'       => self::getTable() . ' AS i',
            'INNER JOIN' => [
                Drill::getTable() . ' AS d' => ['ON' => ['i' => self::$items_id_1, 'd' => 'id']],
            ],
            'WHERE'      => ['i.itemtype' => $asset::class, 'i.items_id' => $asset->getID()],
        ]) as $r) {
            $set = ['asset_entities_id' => $snapshot['asset_entities_id']];
            if ($moved || $asset->isAccessibleFromEntities([(int) $r['drill_entity']])) {
                $set['asset_name'] = $snapshot['asset_name'];
            }
            $DB->update(self::getTable(), $set, ['id' => (int) $r['id']]);
        }
    }

    /**
     * How to show the asset of a link row to the current user.
     *
     * - `visible`: the drill's entity can see the asset. Live name; a link
     *   only when the user may open the asset.
     * - `moved`:   the asset now belongs to an entity the drill cannot see.
     *   The stored name and that entity; never live asset data, and a link
     *   only when the user may open the asset.
     * - `missing`: the asset no longer exists.
     *
     * @param array<string,mixed> $row one drills_items row
     * @return array{state:string, name:string, html:string, moved_to:string, entities_id:int, asset:?CommonDBTM}
     */
    public static function assetView(array $row, int $drillEntity): array
    {
        global $DB;

        $asset = getItemForItemtype((string) $row['itemtype']);
        if (!$asset || !$asset->getFromDB((int) $row['items_id'])) {
            $name = trim((string) ($row['asset_name'] ?? '')) ?: NOT_AVAILABLE;
            return ['state' => 'missing', 'name' => $name, 'html' => htmlescape($name), 'moved_to' => '', 'entities_id' => -1, 'asset' => null];
        }

        $canOpen = $asset->can($asset->getID(), READ);

        if ($asset->isAccessibleFromEntities([$drillEntity])) {
            $name = (string) $asset->getName();
            // A link added before 1.0.0 whose snapshot the upgrade could not take.
            if (array_key_exists('asset_name', $row) && (string) $row['asset_name'] === '' && (int) ($row['id'] ?? 0) > 0) {
                $DB->update(self::getTable(), self::snapshotOf($asset), ['id' => (int) $row['id']]);
            }
            return [
                'state'       => 'visible',
                'name'        => $name,
                'html'        => $canOpen ? $asset->getLink() : htmlescape($name),
                'moved_to'    => '',
                'entities_id' => (int) $asset->getEntityID(),
                'asset'       => $asset,
            ];
        }

        $name    = trim((string) ($row['asset_name'] ?? '')) ?: (string) $asset::getTypeName(1);
        $eid     = (int) ($row['asset_entities_id'] ?? 0);
        $movedTo = (string) Dropdown::getDropdownName('glpi_entities', $eid);
        $label   = sprintf(__('%1$s (moved to %2$s)', 'drillreport'), $name, $movedTo);
        return [
            'state'       => 'moved',
            'name'        => $label,
            'html'        => $canOpen
                ? "<a href='" . htmlescape($asset->getLinkURL()) . "'>" . htmlescape($label) . "</a>"
                : htmlescape($label),
            'moved_to'    => $movedTo,
            'entities_id' => $eid,
            'asset'       => null,
        ];
    }

    /**
     * Notice for the drill form when EVERY system in scope has moved to an
     * entity the drill cannot see: recommend transferring the drill when
     * they all went to one entity, otherwise only say where they went. The
     * drill is never moved automatically. '' when there is nothing to say.
     */
    public static function movedNotice(Drill $drill): string
    {
        global $DB;

        $entities = [];
        $total    = 0;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id_1 => $drill->getID()],
        ]) as $r) {
            $total++;
            $view = self::assetView($r, (int) $drill->fields['entities_id']);
            if ($view['state'] !== 'moved') {
                return '';
            }
            $entities[$view['entities_id']] = $view['moved_to'];
        }
        if ($total === 0) {
            return '';
        }

        if (count($entities) === 1) {
            return sprintf(
                __('All systems in scope of this drill now belong to the entity "%s". Consider transferring the drill there: a user with the Transfer right can add it to the transfer list from the DR drills list.', 'drillreport'),
                (string) reset($entities)
            );
        }
        return sprintf(
            __('All systems in scope of this drill have moved to other entities (%s). The drill stays in its entity.', 'drillreport'),
            implode(', ', $entities)
        );
    }

    public function prepareInputForAdd($input)
    {
        $linkable = is_array($input)
            ? self::linkableAsset(
                (string) ($input['itemtype'] ?? ''),
                (int) ($input['items_id'] ?? 0),
                (int) ($input[self::$items_id_1] ?? 0)
            )
            : null;
        if (is_array($input) && $linkable === null) {
            Session::addMessageAfterRedirect(
                __s('This asset cannot be added: it is not an allowed asset type, or it is not visible from the drill\'s entity.', 'drillreport'),
                false,
                ERROR
            );
            return false;
        }

        $input = parent::prepareInputForAdd($input);
        if (!is_array($input)) {
            return $input;
        }
        $input = $this->nullifyEmpty($input);
        if ($linkable !== null) {
            $input = self::snapshotOf($linkable) + $input;
        }

        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);
        if ($itemtype !== '' && $items_id > 0) {
            $t = self::fieldsSoftReadTargets($itemtype, $items_id);
            if (($input['rto_target_minutes'] ?? 'NULL') === 'NULL' && isset($t['rto'])) {
                $input['rto_target_minutes'] = $t['rto'];
            }
            if (($input['rpo_target_minutes'] ?? 'NULL') === 'NULL' && isset($t['rpo'])) {
                $input['rpo_target_minutes'] = $t['rpo'];
            }
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // The snapshot is written by this class only, never from a form.
        if (is_array($input)) {
            unset($input['asset_name'], $input['asset_entities_id']);
        }
        $input = parent::prepareInputForUpdate($input);
        return is_array($input) ? $this->nullifyEmpty($input) : $input;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function nullifyEmpty(array $input): array
    {
        foreach (self::NULLABLE_FIELDS as $k) {
            if (array_key_exists($k, $input) && trim((string) $input[$k]) === '') {
                $input[$k] = 'NULL';
            }
        }
        return $input;
    }

    /**
     * Candidate custom-field column names from the `fields` plugin that could
     * hold an RTO/RPO target — every column on a `glpi_plugin_fields_*` table
     * that also carries `itemtype`/`items_id`, minus the plumbing columns.
     * Used to build the Setup dropdown so the key can't be mistyped.
     *
     * @return list<string> sorted, unique
     */
    public static function availableFieldsColumns(): array
    {
        global $DB;

        if (!class_exists('\\Plugin') || !\Plugin::isPluginActive('fields')) {
            return [];
        }

        $skip = ['id', 'items_id', 'itemtype', 'plugin_fields_containers_id', 'entities_id', 'is_recursive', 'date_creation', 'date_mod'];
        $cols = [];
        foreach ($DB->listTables('glpi\\_plugin\\_fields\\_%') as $trow) {
            $table = $trow['TABLE_NAME'];
            if (!$DB->fieldExists($table, 'itemtype') || !$DB->fieldExists($table, 'items_id')) {
                continue;
            }
            foreach ($DB->listFields($table) as $name => $meta) {
                if (!in_array($name, $skip, true) && !str_ends_with((string) $name, 'dropdowns_id')) {
                    $cols[$name] = true;
                }
            }
        }
        $cols = array_keys($cols);
        sort($cols);
        return $cols;
    }

    /**
     * Best-effort read of RTO/RPO target custom fields for an asset, provided by
     * the `fields` plugin. Scans `glpi_plugin_fields_*` tables for one that has
     * both configured key columns plus `itemtype`/`items_id`, and returns the
     * row's values. Returns [] when `fields` is inactive or nothing matches —
     * the exact container/field names are confirmed on VM-HQ-GLPI (docs §11).
     *
     * @return array{rto?:int, rpo?:int}
     */
    public static function fieldsSoftReadTargets(string $itemtype, int $items_id): array
    {
        global $DB;

        if (!class_exists('\\Plugin') || !\Plugin::isPluginActive('fields')) {
            return [];
        }

        $rtoKey = (string) Config::get('fields_rto_key');
        $rpoKey = (string) Config::get('fields_rpo_key');
        if ($rtoKey === '' && $rpoKey === '') {
            return [];
        }

        $out = [];
        foreach ($DB->listTables('glpi\\_plugin\\_fields\\_%') as $trow) {
            $table = $trow['TABLE_NAME'];
            if (!$DB->fieldExists($table, 'itemtype') || !$DB->fieldExists($table, 'items_id')) {
                continue;
            }
            $hasRto = $rtoKey !== '' && $DB->fieldExists($table, $rtoKey);
            $hasRpo = $rpoKey !== '' && $DB->fieldExists($table, $rpoKey);
            if (!$hasRto && !$hasRpo) {
                continue;
            }

            $data = $DB->request([
                'FROM'  => $table,
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
                'LIMIT' => 1,
            ])->current();
            if (!$data) {
                continue;
            }
            if ($hasRto && $data[$rtoKey] !== null && $data[$rtoKey] !== '') {
                $out['rto'] = (int) $data[$rtoKey];
            }
            if ($hasRpo && $data[$rpoKey] !== null && $data[$rpoKey] !== '') {
                $out['rpo'] = (int) $data[$rpoKey];
            }
            if ($out !== []) {
                break;
            }
        }
        return $out;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $withtemplate) {
            return '';
        }

        // "Systems in scope" tab on the Drill form.
        if ($item instanceof Drill && (int) $item->getID() > 0) {
            $nb = countElementsInTable(self::getTable(), [self::$items_id_1 => $item->getID()]);
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb, $item::class);
        }

        // "DR drill history" on an asset is a section of the shared "PGN Suite"
        // asset tab now ({@see AssetSections}), not a tab of this class.
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Drill) {
            self::showForDrill($item);
            return true;
        }
        return false;
    }

    /**
     * Edit form for one drill/asset row (RTO/RPO target vs actual, recovery).
     * The asset link itself is not editable here — delete + re-add to change it.
     */
    public function showForm($ID, array $options = [])
    {
        $drills_id = (int) ($options['parent_id'] ?? $this->fields[self::$items_id_1] ?? 0);
        if ($ID > 0 && !$this->getFromDB($ID)) {
            return false;
        }
        $canedit = Session::haveRight(self::$rightname, UPDATE);

        $assetLabel = '';
        $drill = new Drill();
        if ($ID > 0 && $drill->getFromDB((int) $this->fields[self::$items_id_1])) {
            $assetLabel = self::assetView($this->fields, (int) $drill->fields['entities_id'])['html'];
        }

        echo "<div class='card'><div class='card-body'>";
        $backDid = (int) ($this->fields[self::$items_id_1] ?? $drills_id);
        if ($ID > 0 && $backDid > 0) {
            echo "<a class='btn btn-sm btn-ghost-secondary mb-3' href='" . htmlescape(Drill::getFormURLWithID($backDid))
                . "'><i class='ti ti-arrow-left'></i> " . __s('Back to drill', 'drillreport') . "</a>";
        }
        echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' data-track-changes='true'>";
        echo "<input type='hidden' name='id' value='" . (int) $ID . "'>";
        echo "<input type='hidden' name='" . self::$items_id_1 . "' value='" . (int) ($this->fields[self::$items_id_1] ?? $drills_id) . "'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'><td>" . __s('Asset') . "</td><td colspan='3'>{$assetLabel}</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('DR failover target', 'drillreport') . "</td><td colspan='3'>"
            . Html::input('dr_target', ['value' => $this->fields['dr_target'] ?? '', 'size' => 50, 'placeholder' => 'DR_DBSERVER2 / 192.168.230.87'])
            . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('RTO target (min)', 'drillreport') . "</td><td>"
            . Html::input('rto_target_minutes', ['type' => 'number', 'min' => 0, 'value' => $this->fields['rto_target_minutes'] ?? '']) . "</td>"
            . "<td>" . __s('RTO actual (min)', 'drillreport') . "</td><td>"
            . Html::input('rto_actual_minutes', ['type' => 'number', 'min' => 0, 'value' => $this->fields['rto_actual_minutes'] ?? '']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('RPO target (min)', 'drillreport') . "</td><td>"
            . Html::input('rpo_target_minutes', ['type' => 'number', 'min' => 0, 'value' => $this->fields['rpo_target_minutes'] ?? '']) . "</td>"
            . "<td>" . __s('RPO actual (min)', 'drillreport') . "</td><td>"
            . Html::input('rpo_actual_minutes', ['type' => 'number', 'min' => 0, 'value' => $this->fields['rpo_actual_minutes'] ?? '']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Recovered', 'drillreport') . "</td><td>";
        Dropdown::showYesNo('is_recovered', (int) ($this->fields['is_recovered'] ?? 0));
        echo "</td><td>" . __s('Recovery order', 'drillreport') . "</td><td>"
            . Html::input('recovery_order', ['type' => 'number', 'min' => 0, 'value' => $this->fields['recovery_order'] ?? '']) . "</td></tr>";
        echo "<tr class='tab_bg_1'><td>" . __s('Comment') . "</td><td colspan='3'>"
            . "<textarea class='form-control' rows='2' name='comment'>" . htmlescape($this->fields['comment'] ?? '') . "</textarea></td></tr>";
        echo "</table>";
        if ($canedit && $ID > 0) {
            echo "<div class='center mt-2'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "&nbsp;" . Html::submit(_sx('button', 'Delete permanently'), ['name' => 'purge', 'class' => 'btn btn-outline-danger', 'confirm' => __('Confirm the final deletion?')]);
            echo "</div>";
        }
        Html::closeForm();
        echo "</div></div>";
        return true;
    }

    private static function showForDrill(Drill $drill): void
    {
        global $DB;

        $canedit = Session::haveRight(self::$rightname, UPDATE);
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id_1 => $drill->getID()],
            'ORDER' => ['recovery_order', 'id'],
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __s('Asset') . "</th>"
            . "<th>" . __s('DR failover target', 'drillreport') . "</th>"
            . "<th>" . __s('RTO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __s('RPO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __s('Recovered', 'drillreport') . "</th>"
            . "<th>" . __s('Order', 'drillreport') . "</th>"
            . "</tr>";
        foreach ($rows as $r) {
            $label = self::assetView($r, (int) $drill->fields['entities_id'])['html'];
            if ($canedit) {
                $label .= " <a href='" . htmlescape(self::getFormURLWithID($r['id']) . '&parent_id=' . $drill->getID()) . "' title='" . __s('Edit') . "'><i class='ti ti-edit'></i></a>";
            }
            echo "<tr class='tab_bg_1'>";
            echo "<td>{$label}</td>";
            echo "<td>" . htmlescape((string) ($r['dr_target'] ?? '') ?: '—') . "</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rto_target_minutes'] ?? '—', $r['rto_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rpo_target_minutes'] ?? '—', $r['rpo_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . ((int) $r['is_recovered'] === 1 ? __('Yes') : __('No')) . "</td>";
            echo "<td>" . htmlescape((string) ($r['recovery_order'] ?? '')) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";

        if (!$canedit) {
            return;
        }

        $itemtypes = [];
        foreach (self::getLinkedAssetItemtypes() as $t) {
            $itemtypes[$t] = $t::getTypeName(1);
        }

        echo "<h4 class='mt-3'>" . __s('Add a system to scope', 'drillreport') . "</h4>";
        echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "'>";
        echo "<input type='hidden' name='" . self::$items_id_1 . "' value='" . (int) $drill->getID() . "'>";
        echo "<input type='hidden' name='add' value='1'>";
        Dropdown::showSelectItemFromItemtypes([
            'itemtypes'       => array_keys($itemtypes),
            'entity_restrict' => $drill->fields['entities_id'],
            'checkright'      => true,
        ]);
        echo "<div class='mt-2'>" . Html::submit(_sx('button', 'Add'), ['class' => 'btn btn-primary']) . "</div>";
        Html::closeForm();
        echo "<p class='text-muted'>" . __s('RTO/RPO targets are pre-filled from the asset\'s custom fields when available.', 'drillreport') . "</p>";
    }

    /**
     * "DR drill history" body — rendered as the drillreport section of the
     * shared "PGN Suite" asset tab ({@see AssetSections::renderDrills()}).
     */
    public static function showForAsset(CommonDBTM $item): void
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [
                'd.id AS drill_id', 'd.name AS drill_name', 'd.date_drill', 'd.overall_result',
                'i.rto_target_minutes', 'i.rto_actual_minutes', 'i.is_recovered',
            ],
            'FROM'  => self::getTable() . ' AS i',
            'INNER JOIN' => [
                Drill::getTable() . ' AS d' => ['ON' => ['i' => self::$items_id_1, 'd' => 'id']],
            ],
            // Only drills of the viewer's entities: the asset can be visible
            // to someone who may not open the drill.
            'WHERE' => ['i.itemtype' => $item::class, 'i.items_id' => $item->getID()]
                + getEntitiesRestrictCriteria('d', '', '', true),
            'ORDER' => 'd.date_drill DESC',
        ]);

        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr>"
            . "<th>" . __('Drill date', 'drillreport') . "</th>"
            . "<th>" . Drill::getTypeName(1) . "</th>"
            . "<th>" . __('Overall result', 'drillreport') . "</th>"
            . "<th>" . __('RTO target/actual (min)', 'drillreport') . "</th>"
            . "<th>" . __('Recovered', 'drillreport') . "</th>"
            . "</tr>";
        $drill = new Drill();
        foreach ($rows as $r) {
            $link = $drill->getFromDB($r['drill_id']) ? $drill->getLink() : htmlescape((string) $r['drill_name']);
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape((string) $r['date_drill']) . "</td>";
            echo "<td>{$link}</td>";
            echo "<td>" . htmlescape(Drill::resultLabels()[$r['overall_result']] ?? $r['overall_result']) . "</td>";
            echo "<td>" . htmlescape(sprintf('%s / %s', $r['rto_target_minutes'] ?? '—', $r['rto_actual_minutes'] ?? '—')) . "</td>";
            echo "<td>" . ((int) $r['is_recovered'] === 1 ? __('Yes') : __('No')) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }
}
