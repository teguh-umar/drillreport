<?php

/**
 * DR Drill Report — drill objective controller (child of Drill).
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\Objective;

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

if (isset($_POST['add'])) {
    $obj->add($_POST);
    $backToDrill();
} elseif (isset($_POST['update'])) {
    $obj->update($_POST);
    $backToDrill();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $obj->delete($_POST, true);
    $backToDrill();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    $menus = ['central' => ['management', ''], 'helpdesk' => []];
    Html::header(Objective::getTypeName(1), '', 'drillreport', 'drill');
    (new Objective())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
