<?php

/**
 * DR Drill Report — drill objective controller (child of Drill).
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\EntityScope;
use GlpiPlugin\Drillreport\Objective;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Objective::$rightname, UPDATE);

$obj = new Objective();

// After any mutation, return to the parent drill — the child's own edit page is
// stale once the row is deleted.
$backToDrill = static function (): void {
    $did = (int) ($_POST[Objective::$items_id] ?? $_POST['parent_id'] ?? 0);
    if ($did > 0) {
        Html::redirect(Drill::getFormURLWithID($did));
    }
    Html::back();
};

// The right alone says nothing about the entity: every branch also checks
// that the user may open the drill the row belongs to (EntityScope). A row
// stays on its drill: the drill posted with an update is ignored.
if (isset($_POST['add'])) {
    EntityScope::checkDrill((int) ($_POST[Objective::$items_id] ?? 0));
    $obj->add($_POST);
    $backToDrill();
} elseif (isset($_POST['update'])) {
    EntityScope::checkChild($obj, (int) ($_POST['id'] ?? 0));
    $_POST[Objective::$items_id] = (int) $obj->fields[Objective::$items_id];
    $obj->update($_POST);
    $backToDrill();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    EntityScope::checkChild($obj, (int) ($_POST['id'] ?? 0));
    $_POST[Objective::$items_id] = (int) $obj->fields[Objective::$items_id];
    $obj->delete($_POST, true);
    $backToDrill();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    if ((int) $_GET['id'] > 0) {
        EntityScope::checkChild($obj, (int) $_GET['id']);
    } else {
        EntityScope::checkDrill($drills_id);
    }
    Html::header(Objective::getTypeName(1), '', SuiteMenu::SECTOR, 'drillreport', 'drill');
    SuiteMenu::renderSubNav('drillreport', 'drill');
    (new Objective())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
