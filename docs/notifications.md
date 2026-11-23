# Enabling drillreport's email notifications

Since v0.7.0, two cron tasks raise real GLPI notification events instead of
only logging:

| Cron task | Event | Fires | Raised against |
|---|---|---|---|
| `DrillReminder` | `reminder` | daily, only in the configured reminder month (Setup → DR Drill Report → `reminder_month`, default **November**), once per entity that has ever run a drill but has none submitted/approved this calendar year | itemtype **DR drill** |
| `ActionFollowup` | `overdue` | weekly, once per entity with overdue open/in-progress improvement actions (`date_due` in the past) | itemtype **Improvement action** |

## Since v0.7.1: a default is seeded for you

Install (`Seed::seedNotifications()`) creates one **Notification** +
**NotificationTemplate** per event automatically — Indonesian subject/body,
active, recipient = **entity administrator** (the `Entity` admin_email field;
a real GLPI concept, not guessed by this plugin). If that's already
delivering for other core notifications on this GLPI instance, `DrillReminder`
/ `ActionFollowup` work with **zero extra setup**.

It's a starting point, not the last word — open **Setup → Notifications →
Notifications**, find the two `DR drill` / `Improvement action` entries, and:
- **Recipients tab** → tick a **Group** (the DR/BCP group) if you want the
  team notified directly instead of/alongside the entity administrator.
- Edit the linked **NotificationTemplate**'s content freely — it's a normal
  template, drillreport never overwrites it once created.
- Deactivate or delete either entry if you don't want it at all.

These seeded rows only appear after `plugin:install` has run *on v0.7.1+* —
on a site upgraded from an earlier version, re-run it once:
```bash
sudo -u nginx php bin/console glpi:plugin:install drillreport --force
```
(idempotent — safe to run any time; see `docs/deploy-checklist.md`.) If you'd
rather build the Notification yourself instead of using the seeded one, the
manual steps are below.

## Manual setup (skip if the v0.7.1 seed already covers your case)

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
   sudo -u nginx php /var/www/glpi/front/cron.php DrillReminder
   sudo -u nginx php /var/www/glpi/front/cron.php ActionFollowup
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
| `##action.list_url##` | Flat link to the DR drills list (no single action to deep-link to at this level) |
| `##actions##` | Repeatable block — one row per overdue action, exposing `##action.name##`, `##action.drill##`, `##action.severity##`, `##action.due##`, `##action.url##` (needs the template editor's tag picker to wrap it correctly — the seeded template uses `##action.list_url##` instead to stay simple) |

Both tag sets come from `NotificationTargetDrill` / `NotificationTargetAction`
(`src/`), which also list them via `getTags()` so GLPI's own tag picker on the
template editor shows them without needing this file.
