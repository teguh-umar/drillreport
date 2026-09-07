<?php

/**
 * DR Drill Report — improvement-action controller (child of Drill), plus the
 * "Send findings to Risk Register" bulk action.
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Action;
use GlpiPlugin\Drillreport\Drill;

Session::checkRight(Action::$rightname, UPDATE);

$action = new Action();

if (isset($_POST['add'])) {
    $action->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $action->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $action->delete($_POST, true);
    Html::back();
} elseif (isset($_POST['send_to_riskregister'])) {
    $drills_id = (int) ($_POST[Action::$items_id] ?? 0);
    $drill = new Drill();
    if ($drills_id > 0 && $drill->getFromDB($drills_id) && $drill->can($drills_id, READ)) {
        $res = Action::sendDrillToRiskRegister($drill);
        if ($res['sent'] > 0) {
            Session::addMessageAfterRedirect(sprintf(
                _n('%d finding sent to Risk Register.', '%d findings sent to Risk Register.', $res['sent'], 'drillreport'),
                $res['sent']
            ));
        }
        if ($res['errors'] > 0) {
            Session::addMessageAfterRedirect(sprintf(
                __('%d finding(s) could not be sent — see the log.', 'drillreport'),
                $res['errors']
            ), false, ERROR);
        }
        if ($res['sent'] === 0 && $res['errors'] === 0) {
            Session::addMessageAfterRedirect(__('Nothing to send.', 'drillreport'), false, WARNING);
        }
    }
    Html::back();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    Html::header(Action::getTypeName(1), '', 'drillreport', 'action');
    (new Action())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
