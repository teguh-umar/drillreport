<?php

namespace GlpiPlugin\Drillreport;

use CommonGLPI;
use Html;
use Session;

/**
 * "DR Drill Report" rights tab on the core Setup → Profiles form.
 *
 * Registered with Plugin::registerClass(self::class, ['addtabon' => 'Profile']).
 * The form posts to core front/profile.form.php — \Profile::prepareInputForUpdate()
 * already loops over every right from ProfileRight::getAllPossibleRights() (which
 * includes our four after install) and post_updateItem() persists them. No custom
 * handler, no manual CSRF (Html::closeForm() emits the token; core validates it).
 */
class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return __('DR Drill Report', 'drillreport');
    }

    public static function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0 && $withtemplate === 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-refresh-alert');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }
        return true;
    }

    public function showRightsForm(int $profiles_id): void
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = self::canUpdate();

        $rights = [];
        foreach (RightDefinition::names() as $name) {
            $rights[] = [
                'itemtype' => '',
                'rights'   => RightDefinition::rightsMatrix($name),
                'label'    => self::labelFor($name),
                'field'    => $name,
            ];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        }

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('DR Drill Report', 'drillreport'),
        ]);

        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }

    private static function labelFor(string $name): string
    {
        return match ($name) {
            'plugin_drillreport_drill'   => Drill::getTypeName(Session::getPluralNumber()),
            'plugin_drillreport_action'  => Action::getTypeName(Session::getPluralNumber()),
            'plugin_drillreport_approve' => __('Approval / sign-off', 'drillreport'),
            'plugin_drillreport_config'  => __('Configuration', 'drillreport'),
            default                      => $name,
        };
    }
}
