# Enabling drillreport's email notifications

Since v0.7.0, two cron tasks raise real GLPI notification events instead of
only logging:

| Cron task | Event | Fires | Raised against |
|---|---|---|---|
| `DrillReminder` | `reminder` | daily, only in the configured reminder month (Setup → DR Drill Report → `reminder_month`, default **November**), once per entity that has ever run a drill but has none submitted/approved this calendar year | itemtype **DR drill** |
| `ActionFollowup` | `overdue` | weekly, once per entity with overdue open/in-progress improvement actions (`date_due` in the past) | itemtype **Improvement action** |

`NotificationEvent::raiseEvent()` is a safe no-op — it returns `false` and
sends nothing — until an admin wires a matching **Notification** in Setup.
Nothing needs re-deploying to turn this on; it's pure GLPI configuration.

## One-time setup (per event you want to enable)

1. **Setup → Notifications → Notification templates → Add**
   - Itemtype: **DR drill** (for `reminder`) or **Improvement action**
     (for `overdue`) — both now appear in the itemtype dropdown.
   - Write the subject/body using the tags listed under "Available tags" on
     the template's Translations tab — see the tag tables below.
2. **Setup → Notifications → Notifications → Add**
   - Itemtype: same as above.
   - Event: **`reminder`** or **`overdue`**.
   - Template: the one just created.
   - Recipients tab: tick who should receive it — **Group** (pick the DR/BCP
     group), **Profile**, or **Entity administrator** / **Administrator**.
     drillreport does not hard-code a recipient since the DR group differs per
     entity/site.
   - Save, make sure the entry is **Active**.
3. Confirm globally: **Setup → General → Notifications** has a mode enabled
   (Mail is the usual one) and mail is actually deliverable from this GLPI
   instance (test with a core notification first if unsure).
4. Test without waiting for the schedule:
   ```bash
   sudo -u nginx php /data/www/glpi/front/cron.php DrillReminder
   sudo -u nginx php /data/www/glpi/front/cron.php ActionFollowup
   ```
   Check `files/_log/drillreport_cron.log` for the raised/skipped counts, and
   `files/_log/mail-*.log` (or `php-errors.log`) if a mail transport issue is
   suspected.

## Tags — DR drill / `reminder`

| Tag | Value |
|---|---|
| `##drill.entity##` | Entity name |
| `##drill.year##` | The year with no submitted/approved drill |
| `##drill.action##` | Fixed label, "Annual DR drill reminder" |
| `##drill.last_name##` | Name of the entity's most recent drill (blank if none) |
| `##drill.last_date##` | Date of that drill |
| `##drill.url##` | Link to the DR drills list |

## Tags — Improvement action / `overdue`

| Tag | Value |
|---|---|
| `##action.entity##` | Entity name |
| `##action.action##` | Fixed label, "Overdue improvement action(s)" |
| `##action.count##` | Number of overdue actions in this notification |
| `##actions##` | Repeatable block — one row per overdue action, exposing `##action.name##`, `##action.drill##`, `##action.severity##`, `##action.due##`, `##action.url##` |

Both tag sets come from `NotificationTargetDrill` / `NotificationTargetAction`
(`src/`), which also list them via `getTags()` so GLPI's own tag picker on the
template editor shows them without needing this file.
