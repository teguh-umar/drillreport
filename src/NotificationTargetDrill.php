<?php

namespace GlpiPlugin\Drillreport;

use Dropdown;
use Html;

/**
 * Notification target for the annual "drill not submitted" reminder
 * ({@see Cron::cronDrillReminder()}).
 *
 * GLPI resolves this class by convention — {@see \NotificationTarget::getInstanceClass()}
 * turns the raising itemtype `GlpiPlugin\Drillreport\Drill` into
 * `GlpiPlugin\Drillreport\NotificationTargetDrill` — no explicit registration needed.
 *
 * Recipients are whatever the admin picks in Setup > Notifications for this
 * itemtype/event (Administrator / Entity administrator / a Profile / a Group —
 * all provided for free by the parent class); this plugin does not hard-code a
 * recipient, since the DR group varies per entity/site.
 */
class NotificationTargetDrill extends \NotificationTarget
{
    public function getEvents()
    {
        return [
            'reminder' => __('This year\'s drill has not been submitted yet', 'drillreport'),
        ];
    }

    public function addDataForTemplate($event, $options = [])
    {
        $this->data['##drill.entity##'] = Dropdown::getDropdownName('glpi_entities', $options['entities_id'] ?? 0);
        $this->data['##drill.year##']   = (string) ($options['year'] ?? date('Y'));
        $this->data['##drill.action##'] = __('Annual DR drill reminder', 'drillreport');
        $this->data['##drill.last_name##'] = (string) ($options['last_name'] ?? '');
        $this->data['##drill.last_date##'] = !empty($options['last_date'])
            ? Html::convDate((string) $options['last_date'])
            : '';
        // No single drill to redirect to (this reminder fires on the *absence*
        // of one) — link straight to the drills list instead of formatURL()'s
        // id-based redirect convention.
        $this->data['##drill.url##'] = Drill::getSearchURL(true);

        $this->getTags();
        foreach ($this->tag_descriptions[\NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'drill.entity'    => Drill::getTypeName(1) . ' — ' . __('Entity'),
            'drill.year'      => __('Year', 'drillreport'),
            'drill.action'    => _n('Event', 'Events', 1),
            'drill.last_name' => __('Most recent drill', 'drillreport'),
            'drill.last_date' => __('Most recent drill date', 'drillreport'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        $tags = ['drill.url' => sprintf(__('%1$s: %2$s'), Drill::getTypeName(1), __('URL'))];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true, 'lang' => false]);
        }

        asort($this->tag_descriptions);
    }
}
