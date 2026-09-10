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

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if (!is_array($input)) {
            return $input;
        }
        $input = $this->nullifyEmpty($input);

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

        // "DR drill history" tab on a supported asset.
        if (in_array($item::class, self::getLinkedAssetItemtypes(), true) && Drill::canView()) {
            $nb = countElementsInTable(self::getTable(), [
                'itemtype' => $item::class,
                'items_id' => $item->getID(),
            ]);
            return self::createTabEntry(
                __('DR drill history', 'drillreport'),
                $nb,
                $item::class,
                Drill::getIcon()
            );
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Drill) {
            self::showForDrill($item);
            return true;
        }
        if ($item instanceof CommonDBTM && in_array($item::class, self::getLinkedAssetItemtypes(), true)) {
            self::showForAsset($item);
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
        $asset = getItemForItemtype((string) ($this->fields['itemtype'] ?? ''));
        if ($asset && $asset->getFromDB((int) ($this->fields['items_id'] ?? 0))) {
            $assetLabel = $asset->getLink();
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
            $asset = getItemForItemtype($r['itemtype']);
            $label = ($asset && $asset->getFromDB($r['items_id'])) ? $asset->getLink() : htmlescape(NOT_AVAILABLE);
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

    private static function showForAsset(CommonDBTM $item): void
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
            'WHERE' => ['i.itemtype' => $item::class, 'i.items_id' => $item->getID()],
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
