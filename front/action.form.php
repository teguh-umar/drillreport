<?php

/**
 * DR Drill Report — improvement-action controller (child of Drill), plus the
 * "Carry over" and "Send findings to Risk Register" actions.
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Action;
use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Action::$rightname, UPDATE);

$action = new Action();

// After any mutation, return to the parent drill — the child's own edit page is
// stale once the row is deleted.
$backToDrill = static function (): void {
    $did = (int) ($_POST[Action::$items_id] ?? $_POST['parent_id'] ?? 0);
    if ($did > 0) {
        Html::redirect(Drill::getFormURLWithID($did));
    }
    Html::back();
};

if (isset($_POST['add'])) {
    $action->add($_POST);
    $backToDrill();
} elseif (isset($_POST['update'])) {
    $action->update($_POST);
    $backToDrill();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $action->delete($_POST, true);
    $backToDrill();
} elseif (isset($_POST['carry_over'])) {
    $drills_id = (int) ($_POST[Action::$items_id] ?? 0);
    $drill = new Drill();
    if ($drills_id > 0 && $drill->getFromDB($drills_id) && $drill->can($drills_id, READ)) {
        if (Action::carryOver((int) ($_POST['source_actions_id'] ?? 0), $drill) > 0) {
            Session::addMessageAfterRedirect(__s('Improvement action carried over.', 'drillreport'));
        } else {
            Session::addMessageAfterRedirect(__s('Nothing was carried over — pick an action first.', 'drillreport'), false, WARNING);
        }
    }
    $backToDrill();
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
    Html::header(Action::getTypeName(1), '', SuiteMenu::SECTOR, 'drillreport', 'action');
    SuiteMenu::renderSubNav('drillreport', 'action');
    (new Action())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
