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
use GlpiPlugin\Drillreport\EntityScope;
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

// The right alone says nothing about the entity: every branch also checks
// that the user may open the drill the row belongs to (EntityScope). A row
// stays on its drill and its asset: both are ignored when posted with an update.
if (isset($_POST['add'])) {
    EntityScope::checkDrill((int) ($_POST[Drill_Item::$items_id_1] ?? 0));
    if (!empty($_POST['itemtype']) && (int) ($_POST['items_id'] ?? 0) > 0) {
        $link->add($_POST);
    } else {
        Session::addMessageAfterRedirect(__('Pick an asset first.', 'drillreport'), false, ERROR);
    }
    $backToDrill();
} elseif (isset($_POST['update'])) {
    EntityScope::checkChild($link, (int) ($_POST['id'] ?? 0));
    $_POST[Drill_Item::$items_id_1] = (int) $link->fields[Drill_Item::$items_id_1];
    unset($_POST['itemtype'], $_POST['items_id']);
    $link->update($_POST);
    $backToDrill();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    EntityScope::checkChild($link, (int) ($_POST['id'] ?? 0));
    $_POST[Drill_Item::$items_id_1] = (int) $link->fields[Drill_Item::$items_id_1];
    $link->delete($_POST, true);
    $backToDrill();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    EntityScope::checkChild($link, (int) $_GET['id']);
    Html::header(Drill_Item::getTypeName(1), '', SuiteMenu::SECTOR, 'drillreport', 'drill');
    SuiteMenu::renderSubNav('drillreport', 'drill');
    (new Drill_Item())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
