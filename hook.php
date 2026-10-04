<?php

use GlpiPlugin\Drillreport\Migration as DRMigration;
use GlpiPlugin\Drillreport\RightDefinition;
use GlpiPlugin\Drillreport\Config;
use GlpiPlugin\Drillreport\Cron;
use GlpiPlugin\Drillreport\Seed;

function plugin_drillreport_install(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $glpiMigration = new \Migration(PLUGIN_DRILLREPORT_VERSION);

    // ---- Dropdowns ---------------------------------------------------------
    DRMigration::register('glpi_plugin_drillreport_scenarios', "
        CREATE TABLE `glpi_plugin_drillreport_scenarios` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `name` varchar(255) DEFAULT NULL,
          `comment` text,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    DRMigration::register('glpi_plugin_drillreport_testtypes', "
        CREATE TABLE `glpi_plugin_drillreport_testtypes` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `name` varchar(255) DEFAULT NULL,
          `comment` text,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    DRMigration::register('glpi_plugin_drillreport_actioncategories', "
        CREATE TABLE `glpi_plugin_drillreport_actioncategories` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `name` varchar(255) DEFAULT NULL,
          `comment` text,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ---- Parent: one drill report ---------------------------------------
    DRMigration::register('glpi_plugin_drillreport_drills', "
        CREATE TABLE `glpi_plugin_drillreport_drills` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `entities_id` int unsigned NOT NULL DEFAULT 0,
          `is_recursive` tinyint NOT NULL DEFAULT 0,
          `name` varchar(255) NOT NULL DEFAULT '',
          `date_drill` date DEFAULT NULL,
          `plugin_drillreport_scenarios_id` int unsigned NOT NULL DEFAULT 0,
          `plugin_drillreport_testtypes_id` int unsigned NOT NULL DEFAULT 0,
          `groups_id` int unsigned NOT NULL DEFAULT 0,
          `users_id_tech` int unsigned NOT NULL DEFAULT 0,
          `objectives` text,
          `scope_summary` text,
          `declaration_time` timestamp NULL DEFAULT NULL,
          `recovery_time` timestamp NULL DEFAULT NULL,
          `validation_time` timestamp NULL DEFAULT NULL,
          `rto_target_minutes` int unsigned DEFAULT NULL,
          `rto_actual_minutes` int unsigned DEFAULT NULL,
          `rpo_target_minutes` int unsigned DEFAULT NULL,
          `rpo_actual_minutes` int unsigned DEFAULT NULL,
          `data_loss_summary` text,
          `overall_result` varchar(30) NOT NULL DEFAULT 'pending',
          `executive_summary` text,
          `lessons_learned` text,
          `status` varchar(20) NOT NULL DEFAULT 'draft',
          `approved_by` int unsigned NOT NULL DEFAULT 0,
          `approval_date` timestamp NULL DEFAULT NULL,
          `comment` text,
          `is_deleted` tinyint NOT NULL DEFAULT 0,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `entities_id` (`entities_id`),
          KEY `date_drill` (`date_drill`),
          KEY `scenario` (`plugin_drillreport_scenarios_id`),
          KEY `testtype` (`plugin_drillreport_testtypes_id`),
          KEY `groups_id` (`groups_id`),
          KEY `users_id_tech` (`users_id_tech`),
          KEY `overall_result` (`overall_result`),
          KEY `status` (`status`),
          KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ---- Child: per-objective / activity assessment ------------------
    DRMigration::register('glpi_plugin_drillreport_objectives', "
        CREATE TABLE `glpi_plugin_drillreport_objectives` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `plugin_drillreport_drills_id` int unsigned NOT NULL DEFAULT 0,
          `plugin_drillreport_drills_items_id` int unsigned NOT NULL DEFAULT 0,
          `name` varchar(255) NOT NULL DEFAULT '',
          `content` text,
          `target_minutes` int NOT NULL DEFAULT 0,
          `start_time` timestamp NULL DEFAULT NULL,
          `end_time` timestamp NULL DEFAULT NULL,
          `result` varchar(20) NOT NULL DEFAULT 'not_met',
          `evidence_ref` varchar(255) NOT NULL DEFAULT '',
          `comment` text,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `drill` (`plugin_drillreport_drills_id`),
          KEY `drill_item` (`plugin_drillreport_drills_items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ---- Child: finding + improvement plan (maps to riskregister Finding) --
    DRMigration::register('glpi_plugin_drillreport_actions', "
        CREATE TABLE `glpi_plugin_drillreport_actions` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `plugin_drillreport_drills_id` int unsigned NOT NULL DEFAULT 0,
          `plugin_drillreport_drills_items_id` int unsigned NOT NULL DEFAULT 0,
          `source_actions_id` int unsigned NOT NULL DEFAULT 0,
          `plugin_drillreport_actioncategories_id` int unsigned NOT NULL DEFAULT 0,
          `name` varchar(255) NOT NULL DEFAULT '',
          `content` text,
          `cause` text,
          `recommendation` text,
          `severity` varchar(20) NOT NULL DEFAULT 'medium',
          `groups_id` int unsigned NOT NULL DEFAULT 0,
          `users_id` int unsigned NOT NULL DEFAULT 0,
          `date_due` date DEFAULT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'open',
          `riskregister_findings_id` int unsigned NOT NULL DEFAULT 0,
          `riskregister_code` varchar(50) NOT NULL DEFAULT '',
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `drill` (`plugin_drillreport_drills_id`),
          KEY `drill_item` (`plugin_drillreport_drills_items_id`),
          KEY `source_actions_id` (`source_actions_id`),
          KEY `category` (`plugin_drillreport_actioncategories_id`),
          KEY `severity` (`severity`),
          KEY `status` (`status`),
          KEY `groups_id` (`groups_id`),
          KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ---- Child: Drill <-> asset polymorphic link + per-asset RTO/RPO -----
    DRMigration::register('glpi_plugin_drillreport_drills_items', "
        CREATE TABLE `glpi_plugin_drillreport_drills_items` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `plugin_drillreport_drills_id` int unsigned NOT NULL DEFAULT 0,
          `itemtype` varchar(100) NOT NULL DEFAULT '',
          `items_id` int unsigned NOT NULL DEFAULT 0,
          `dr_target` varchar(255) NOT NULL DEFAULT '',
          `rto_target_minutes` int unsigned DEFAULT NULL,
          `rto_actual_minutes` int unsigned DEFAULT NULL,
          `rpo_target_minutes` int unsigned DEFAULT NULL,
          `rpo_actual_minutes` int unsigned DEFAULT NULL,
          `is_recovered` tinyint NOT NULL DEFAULT 0,
          `recovery_order` smallint DEFAULT NULL,
          `comment` text,
          `date_creation` timestamp NULL DEFAULT NULL,
          `date_mod` timestamp NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unicity` (`plugin_drillreport_drills_id`,`itemtype`,`items_id`),
          KEY `item` (`itemtype`,`items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ---- In-place upgrades (idempotent — addField no-ops if the column exists) --
    if ($DB->tableExists('glpi_plugin_drillreport_objectives')) {
        // v0.5.0: objectives gain a time budget + actual start/end + an optional
        // asset link, so an objective doubles as a timed runbook activity.
        // 'fkey' (not 'integer') — GLPI 11's unsigned-keys convention: every
        // *_id column must be INT UNSIGNED. addField() no-ops on an existing
        // column, so this alone only gets fresh installs right.
        $glpiMigration->addField('glpi_plugin_drillreport_objectives', 'plugin_drillreport_drills_items_id', 'fkey', ['after' => 'plugin_drillreport_drills_id']);
        $glpiMigration->addField('glpi_plugin_drillreport_objectives', 'target_minutes', 'integer', ['after' => 'content']); // 0 = no target
        $glpiMigration->addField('glpi_plugin_drillreport_objectives', 'start_time', 'timestamp', ['after' => 'target_minutes']);
        $glpiMigration->addField('glpi_plugin_drillreport_objectives', 'end_time', 'timestamp', ['after' => 'start_time']);
        $glpiMigration->addKey('glpi_plugin_drillreport_objectives', 'plugin_drillreport_drills_items_id');
        // v0.6.0: evidence reference per activity.
        $glpiMigration->addField('glpi_plugin_drillreport_objectives', 'evidence_ref', 'string', ['value' => '', 'after' => 'result']);
        // v0.8.5: retrofit for installs upgraded from before this fix — the
        // column above was created as signed INT (addField('integer', ...)).
        // changeField() no-ops safely if it's already unsigned.
        $glpiMigration->changeField(
            'glpi_plugin_drillreport_objectives',
            'plugin_drillreport_drills_items_id',
            'plugin_drillreport_drills_items_id',
            'fkey'
        );
    }
    if ($DB->tableExists('glpi_plugin_drillreport_drills_items')) {
        // v0.6.0: the DR failover target (name / IP) for this system.
        $glpiMigration->addField('glpi_plugin_drillreport_drills_items', 'dr_target', 'string', ['value' => '', 'after' => 'items_id']);
    }
    if ($DB->tableExists('glpi_plugin_drillreport_actions')) {
        // v0.12.0: an action carried over from another drill remembers the
        // action it was copied from (0 = created here). 'fkey' = INT UNSIGNED.
        $glpiMigration->addField('glpi_plugin_drillreport_actions', 'source_actions_id', 'fkey', ['after' => 'plugin_drillreport_drills_items_id']);
        $glpiMigration->addKey('glpi_plugin_drillreport_actions', 'source_actions_id');
    }

    DRMigration::install($glpiMigration);

    if (class_exists(Seed::class)) {
        Seed::run();
        try {
            // Core Notification/NotificationTemplate object graph — defensive:
            // a failure here must not abort the rest of install().
            Seed::seedNotifications();
        } catch (\Throwable $e) {
            \Toolbox::logInFile('drillreport_install', 'seedNotifications skipped: ' . $e->getMessage() . "\n", true);
        }
    }
    if (class_exists(Config::class)) {
        Config::setDefaults();
    }

    // ---- Cron tasks -----------------------------------------------------
    // CronTask::register is a no-op if the task already exists — safe to re-run.
    if (class_exists(Cron::class)) {
        \CronTask::register(Cron::class, 'DrillReminder', DAY_TIMESTAMP, [
            'mode'      => \CronTask::MODE_EXTERNAL,
            'allowmode' => \CronTask::MODE_INTERNAL | \CronTask::MODE_EXTERNAL,
            'state'     => \CronTask::STATE_WAITING,
            'comment'   => 'DR Drill Report: annual "drill not submitted" reminder',
        ]);
        \CronTask::register(Cron::class, 'ActionFollowup', WEEK_TIMESTAMP, [
            'mode'      => \CronTask::MODE_EXTERNAL,
            'allowmode' => \CronTask::MODE_INTERNAL | \CronTask::MODE_EXTERNAL,
            'state'     => \CronTask::STATE_WAITING,
            'comment'   => 'DR Drill Report: overdue improvement-action notice',
        ]);
    }

    // ---- Profile rights ------------------------------------------------
    // ProfileRight::addProfileRights() blind-INSERTs per (profile, name); the
    // glpi_profilerights UNIQUE(profiles_id, name) would fatal on a re-run, so
    // only add names not already present.
    $existing = array_keys(\ProfileRight::getAllPossibleRights());
    $toAdd    = array_values(array_diff(RightDefinition::names(), $existing));
    if ($toAdd !== []) {
        \ProfileRight::addProfileRights($toAdd);
    }

    // Seed sensible defaults ONLY for rights that didn't exist before this
    // run (i.e. once, the very first time each right is introduced). Every
    // plugin:install after that is a no-op here — an admin's hand-tuned
    // matrix must survive `plugin:install --force` on a routine upgrade,
    // not get silently overwritten back to the "derived from core rights"
    // guess every time. A full-rights profile is the one deliberate
    // exception: it always gets full plugin rights on every run (standard
    // GLPI plugin convention — nobody intentionally under-provisions
    // Super-Admin). Identified by the core `config` UPDATE right, NOT the
    // profile's display name — names are renameable and localised, and
    // matching the literal string 'Super-Admin' silently matches nothing
    // once an admin renames it (suite rule, hub §4, 2026-09-16). If no
    // profile has core config UPDATE, nothing extra is seeded here — never
    // fall back to granting everyone full rights.
    foreach ((new \Profile())->find() as $p) {
        $pid = (int) $p['id'];

        $core         = \ProfileRight::getProfileRights($pid, ['config', 'computer']);
        $configMask   = (int) ($core['config'] ?? 0);
        $computerMask = (int) ($core['computer'] ?? 0);

        if (($configMask & UPDATE) === UPDATE) {
            \ProfileRight::updateProfileRights($pid, [
                'plugin_drillreport_drill'   => ALLSTANDARDRIGHT,
                'plugin_drillreport_action'  => ALLSTANDARDRIGHT,
                'plugin_drillreport_approve' => UPDATE,
                'plugin_drillreport_config'  => READ | UPDATE,
            ]);
            continue;
        }

        if ($toAdd === [] || ($p['interface'] ?? '') !== 'central') {
            continue; // nothing newly introduced this run, or a simplified-interface profile
        }

        $grant = [];
        if (($computerMask & UPDATE) === UPDATE) {
            $grant['plugin_drillreport_drill']  = ALLSTANDARDRIGHT;
            $grant['plugin_drillreport_action'] = ALLSTANDARDRIGHT;
        } elseif (($computerMask & READ) === READ) {
            $grant['plugin_drillreport_drill']  = READ;
            $grant['plugin_drillreport_action'] = READ;
        }
        // Only set rights that are actually new this run — never touch a
        // right the admin may have since customized by hand.
        $grant = array_intersect_key($grant, array_flip($toAdd));
        if ($grant !== []) {
            \ProfileRight::updateProfileRights($pid, $grant);
        }
    }

    return true;
}

/**
 * Search lists of Objective / Action / Drill_Item: join the drill and keep
 * only rows whose drill is in the user's entities. These tables have no
 * `entities_id`, so core adds no entity restriction of its own
 * (Hooks::AUTO_ADD_DEFAULT_JOIN / AUTO_ADD_DEFAULT_WHERE).
 *
 * @param array<int,string> $already_link_tables
 * @return array<string,mixed>
 */
function plugin_drillreport_addDefaultJoin($itemtype, $ref_table, &$already_link_tables): array
{
    return \GlpiPlugin\Drillreport\EntityScope::defaultJoin((string) $itemtype, (string) $ref_table);
}

/** @return array<int|string,mixed> */
function plugin_drillreport_addDefaultWhere($itemtype): array
{
    return \GlpiPlugin\Drillreport\EntityScope::defaultWhere((string) $itemtype);
}

function plugin_drillreport_uninstall(): bool
{
    \CronTask::unregister('drillreport');

    \ProfileRight::deleteProfileRights(RightDefinition::names());

    DRMigration::uninstall();

    if (class_exists(Config::class)) {
        \Config::deleteConfigurationValues(
            'plugin:drillreport',
            array_keys(Config::DEFAULTS)
        );
    }

    return true;
}
