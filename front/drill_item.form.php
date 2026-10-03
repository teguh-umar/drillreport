<?php

/**
 * DR Drill Report — drill / asset link controller (systems in scope).
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\Drill_Item;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Drill_Item::$rightname, UPDATE);

$link = new Drill_Item();

// After any mutation, return to the parent drill (its "Systems in scope" tab),
// not Html::back() — the row's own edit page is stale once it is deleted.
$backToDrill = static function (): void {
    $did = (int) ($_POST[Drill_Item::$items_id_1] ?? $_POST['parent_id'] ?? 0);
    if ($did > 0) {
        Html::redirect(Drill::getFormURLWithID($did));
    }
    Html::back();
};

if (isset($_POST['add'])) {
    if (!empty($_POST['itemtype']) && (int) ($_POST['items_id'] ?? 0) > 0) {
        $link->add($_POST);
    } else {
        Session::addMessageAfterRedirect(__('Pick an asset first.', 'drillreport'), false, ERROR);
    }
    $backToDrill();
} elseif (isset($_POST['update'])) {
    $link->update($_POST);
    $backToDrill();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $link->delete($_POST, true);
    $backToDrill();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    Html::header(Drill_Item::getTypeName(1), '', SuiteMenu::SECTOR, 'drillreport', 'drill');
    SuiteMenu::renderSubNav('drillreport', 'drill');
    (new Drill_Item())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
