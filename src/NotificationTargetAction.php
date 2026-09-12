<?php

namespace GlpiPlugin\Drillreport;

use Dropdown;
use Html;

/**
 * Notification target for the overdue-improvement-action follow-up
 * ({@see Cron::cronActionFollowup()}).
 *
 * GLPI resolves this class by convention — {@see \NotificationTarget::getInstanceClass()}
 * turns the raising itemtype `GlpiPlugin\Drillreport\Action` into
 * `GlpiPlugin\Drillreport\NotificationTargetAction` — no explicit registration needed.
 *
 * Recipients are whatever the admin picks in Setup > Notifications for this
 * itemtype/event (Administrator / Entity administrator / a Profile / a Group).
 */
class NotificationTargetAction extends \NotificationTarget
{
    public function getEvents()
    {
        return [
            'overdue' => __('Overdue improvement action(s)', 'drillreport'),
        ];
    }

    /**
     * @param string $event
     * @param array{entities_id?: int, items?: array<int, array<string, mixed>>} $options
     */
    public function addDataForTemplate($event, $options = [])
    {
        $this->data['##action.entity##'] = Dropdown::getDropdownName('glpi_entities', $options['entities_id'] ?? 0);
        $this->data['##action.action##'] = __('Overdue improvement action(s)', 'drillreport');
        $this->data['##action.count##']  = (string) count($options['items'] ?? []);

        foreach (($options['items'] ?? []) as $id => $row) {
            $tmp = [];
            $tmp['##action.name##']     = (string) ($row['name'] ?? '');
            $tmp['##action.drill##']    = (string) ($row['drill_name'] ?? '');
            $tmp['##action.severity##'] = htmlescape(ucfirst((string) ($row['severity'] ?? '')));
            $tmp['##action.due##']      = !empty($row['date_due']) ? Html::convDate((string) $row['date_due']) : '';
            $tmp['##action.url##']      = $this->formatURL($options['additionnaloption']['usertype'] ?? '', 'Action_' . (int) $id);
            $this->data['actions'][]    = $tmp;
        }

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
            'action.entity' => __('Entity'),
            'action.action' => _n('Event', 'Events', 1),
            'action.count'  => _x('quantity', 'Number of items'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        $tags = [
            'action.name'     => __('Name'),
            'action.drill'    => Drill::getTypeName(1),
            'action.severity' => __('Severity', 'drillreport'),
            'action.due'      => __('Due date', 'drillreport'),
            'action.url'      => sprintf(__('%1$s: %2$s'), Action::getTypeName(1), __('URL')),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true, 'lang' => false]);
        }

        $tags = ['actions' => Action::getTypeName(2)];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => false, 'foreach' => true]);
        }

        asort($this->tag_descriptions);
    }
}
