<?php

/**
 * DR Drill Report — drill / asset link controller (systems in scope).
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Drill_Item;

Session::checkRight(Drill_Item::$rightname, UPDATE);

$link = new Drill_Item();

if (isset($_POST['add'])) {
    if (!empty($_POST['itemtype']) && (int) ($_POST['items_id'] ?? 0) > 0) {
        $link->add($_POST);
    } else {
        Session::addMessageAfterRedirect(__('Pick an asset first.', 'drillreport'), false, ERROR);
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $link->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $link->delete($_POST, true);
    Html::back();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    Html::header(Drill_Item::getTypeName(1), '', 'drillreport', 'drill');
    (new Drill_Item())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
