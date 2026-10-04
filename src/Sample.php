<?php

namespace GlpiPlugin\Drillreport;

/**
 * One fully-worked demo drill, loadable / removable from the Setup screen so a
 * fresh install has a concrete reference. Every row's name is prefixed with
 * {@see self::MARKER} so it is easy to spot and remove.
 *
 * Content is the real PT PGN / ICT CRM DRC drill of 23 August 2026, transcribed
 * from DRILL_CRM_2026_Activities_Plan_v.1.0_Fix.xlsx + DRC-DRILL Agustus 2026
 * CRM.pdf. See docs/entering-a-real-drill.md for the full field-by-field mapping.
 * The multi-asset "Systems in scope" table cannot be seeded (the 8 CRM servers
 * are not guaranteed to exist in the inventory) — one representative asset is
 * auto-linked; add the rest by hand per the walkthrough.
 */
class Sample
{
    public const MARKER = '[SAMPLE] ';

    /** @return int the sample drill id, or 0 if none */
    public static function drillId(): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'id',
            'FROM'   => Drill::getTable(),
            // The user's entities only: the sample of another entity is not theirs.
            'WHERE'  => ['name' => ['LIKE', self::MARKER . '%'], 'is_deleted' => 0]
                + getEntitiesRestrictCriteria(Drill::getTable()),
            'LIMIT'  => 1,
        ])->current();
        return $row ? (int) $row['id'] : 0;
    }

    public static function exists(): bool
    {
        return self::drillId() > 0;
    }

    /**
     * Create the sample drill + its objectives, improvement actions and (if an
     * asset is available in the entity) one system-in-scope row.
     *
     * @return int new drill id, or 0 on failure
     */
    public static function create(int $entities_id): int
    {
        if (self::exists()) {
            return self::drillId();
        }

        $scenarioId = self::dropdownId(new Scenario(), 'CRM Failover to DRC', $entities_id);
        $testTypeId = self::dropdownId(new TestType(), 'Full failover', $entities_id);

        $drill = new Drill();
        $drillId = (int) $drill->add([
            'entities_id'                     => $entities_id,
            'name'                            => self::MARKER . 'DR Drill 2026 — CRM Failover to DRC',
            'date_drill'                      => '2026-08-23',
            'declaration_time'                => '2026-08-23 21:09:00',
            'recovery_time'                   => '2026-08-23 22:11:00',
            'validation_time'                 => '2026-08-23 23:10:00',
            'plugin_drillreport_scenarios_id' => $scenarioId,
            'plugin_drillreport_testtypes_id' => $testTypeId,
            'groups_id'                       => 0,
            'users_id_tech'                   => (int) ($_SESSION['glpiID'] ?? 0),
            'objectives'                      => "1. CRM (Siebel 8.1) online di DRC pasca-failover, dieksekusi tim internal.\n"
                                               . "2. Integritas data CRM terjaga — switchover Oracle Data Guard tanpa gap.\n"
                                               . "3. RMS/HQAPEX dan Gapura Online Payment berfungsi di DRC.\n"
                                               . "4. Payment Engine (MyPertamina / PGN Mobile) berfungsi di DRC.\n"
                                               . "5. Fallback DRC → DC bersih dan layanan normal kembali.",
            'scope_summary'                   => 'Failover terkontrol aplikasi CRM (Siebel 8.1, web + database Oracle Data Guard), '
                                               . 'RMS/HQAPEX, Gapura Online Payment, dan Payment Engine via MyPertamina/PGNMobile '
                                               . 'dari Pusat Data Utama (Jl. K.H Zainul Arifin 20, Jakarta) ke DRC Indosat Jatiluhur, '
                                               . 'lalu dikembalikan. Skenario: kegagalan total DC HQ / DB primary CRM.',
            'data_loss_summary'               => 'Tidak ada kehilangan data — pengecekan gap primary/standby "Sukses" setelah '
                                               . 'fallback (no archive gap). RPO tidak dikuantifikasi di sumber. Risiko data-loss '
                                               . 'untuk step fallback diusulkan masuk risk register.',
            'overall_result'                  => 'pass_with_exceptions',
            'executive_summary'               => 'Failover CRM berjalan baik secara fungsional (aplikasi online, data konsisten, '
                                               . 'tanpa bantuan vendor). RTO meleset: total downtime ~104 menit vs rencana ~65 menit; '
                                               . 'penyebab utama tidak ada standby database ter-restore di DRC. 1 sub-test gagal: '
                                               . 'pembayaran via MyPertamina & PGN Mobile (konfigurasi IP API belum diarahkan ke '
                                               . 'DB DRC). Rencana perbaikan Q4 2026.',
            'lessons_learned'                 => 'Runbook failover akurat dan dieksekusi tim internal. Bottleneck di infrastruktur '
                                               . 'data & konfigurasi integrasi, bukan prosedur. Perlu keterlibatan tim MIS untuk '
                                               . 'konfigurasi API pembayaran, penambahan resource HQAPEX, dan monitoring rutin '
                                               . 'sinkronisasi archivelog.',
            'status'                          => 'submitted',
        ]);

        if ($drillId <= 0) {
            return 0;
        }

        // ---- Objectives / activities — the 7 runbook activities, planned
        //      minutes as target + actual start/end times (xlsx execution log).
        foreach ([
            ['Backup database & config check', 210, '2026-08-23 18:00:00', '2026-08-23 20:17:00', 'met', '01_backup_disk.png', 'Disk space cukup; selesai lebih cepat. (bukan downtime)'],
            ['Shutdown aplikasi CRM, RMS & Gapura', 15, '2026-08-23 21:09:00', '2026-08-23 21:18:00', 'met', '02_shutdown_nosessions.png', 'Tidak ada sesi aplikasi di database.'],
            ['Switchover database DC → DRC', 20, '2026-08-23 21:18:00', '2026-08-23 21:30:00', 'met', '03_switchover_db_2130.png', 'Primary ↔ Standby switch Oracle Data Guard, tidak ada gap.'],
            ['Failover VM CRM/RMS + re-IP + hosts + Gapura conn.string', 50, '2026-08-23 21:30:00', '2026-08-23 22:11:00', 'partial', '04_vm_failover.png', 'Sukses tapi kendala NIC DRCBAY11 & Guest OS RMS unsupported.'],
            ['Test CRM, RMS, Gapura & Payment Engine di DRC', 24, '2026-08-23 22:12:00', '2026-08-23 22:45:00', 'partial', '05a_crm_test.png; 05b_payment_ok.png; 05c_pgnmobile_fail.png', 'CRM/RMS/Gapura Sukses; Payment via PGN Mobile GAGAL.'],
            ['Switchover database DRC → DC (fallback)', 14, '2026-08-23 22:46:00', '2026-08-23 22:55:00', 'met', '06_fallback_gap0.png', 'Delete test-failover VM, gap check (0), switchover — Sukses.'],
            ['Startup CRM/RMS di DC + test akhir', 30, '2026-08-23 22:56:00', '2026-08-23 23:10:00', 'met', '07_final_test.png', 'CRM, RMS, Gapura Online Payment normal.'],
        ] as [$name, $target, $start, $end, $result, $evidence, $comment]) {
            (new Objective())->add([
                'plugin_drillreport_drills_id' => $drillId,
                'name'                         => $name,
                'target_minutes'               => $target,
                'start_time'                   => $start,
                'end_time'                     => $end,
                'result'                       => $result,
                'evidence_ref'                 => $evidence,
                'comment'                      => $comment,
            ]);
        }

        // ---- Improvement actions — the 5 findings from the PDF result report.
        //      No due dates in the source; leave them blank.
        $infraCat = self::dropdownId(new ActionCategory(), 'Infrastructure / capacity', $entities_id);
        $actions = [
            [
                'name'           => 'Pembayaran via MyPertamina & PGN Mobile gagal saat test DRC',
                'category'       => self::dropdownId(new ActionCategory(), 'Network & connectivity', $entities_id),
                'severity'       => 'high',
                'content'        => 'Test Payment Engine via PGN Mobile — Gagal. Error "Unexpected system error", "Token is not valid".',
                'cause'          => 'Konfigurasi IP pada sisi API (Web Method) belum diarahkan ke IP DB DRC.',
                'recommendation' => 'Libatkan tim MIS di drill berikutnya untuk menyesuaikan konfigurasi API di Web Method ke IP DB DRC.',
            ],
            [
                'name'           => 'HQAPEX (RMS) kekurangan RAM & disk',
                'category'       => $infraCat,
                'severity'       => 'medium',
                'content'        => 'RAM VM DR 1 GB; disk `/` 89% penuh.',
                'cause'          => 'Alokasi RAM & disk tidak memadai.',
                'recommendation' => 'Naikkan RAM HQAPEX menjadi 8 GB dan available disk `/` menjadi 100 GB.',
            ],
            [
                'name'           => 'Sinkronisasi archivelog primary → standby perlu diawasi',
                'category'       => self::dropdownId(new ActionCategory(), 'Monitoring & alerting', $entities_id),
                'severity'       => 'low',
                'content'        => 'Risiko gap antara primary dan standby.',
                'cause'          => '',
                'recommendation' => 'Lakukan monitoring rutin proses sinkronisasi archivelog dari primary ke standby.',
            ],
            [
                'name'           => 'Node DRCBAY11 — NIC VM tidak bisa dikonfigurasi',
                'category'       => $infraCat,
                'severity'       => 'medium',
                'content'        => 'Kendala pada NIC untuk jaringan VM; issue pada modul Onboard Administrator. Workaround: semua VM SIEBEL di DRCBAY09.',
                'cause'          => 'Fault pada modul Onboard Administrator.',
                'recommendation' => 'Perbaiki modul Onboard Administrator DRCBAY11.',
            ],
            [
                'name'           => 'VM-RMS — perubahan IP terhambat, Guest OS unsupported',
                'category'       => $infraCat,
                'severity'       => 'low',
                'content'        => 'Kursor GUI freeze/unresponsive saat input konfigurasi.',
                'cause'          => 'Versi Guest OS sudah tidak didukung oleh Hyper-V.',
                'recommendation' => 'Upgrade / migrasi Guest OS VM-RMS ke versi yang didukung.',
            ],
        ];
        foreach ($actions as $a) {
            (new Action())->add([
                'plugin_drillreport_drills_id'           => $drillId,
                'plugin_drillreport_actioncategories_id' => $a['category'],
                'name'                                   => self::MARKER . $a['name'],
                'severity'                               => $a['severity'],
                'status'                                 => 'open',
                'content'                                => $a['content'],
                'cause'                                  => $a['cause'],
                'recommendation'                         => $a['recommendation'],
            ]);
        }

        // ---- One representative system in scope, if any asset exists.
        //      The real drill covered 8 CRM servers (see the walkthrough); seed
        //      just one — the CRM DB — and add the rest by hand.
        foreach (Drill_Item::getLinkedAssetItemtypes() as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }
            $asset = new $itemtype();
            // An asset of the sample drill's entity only (Drill_Item refuses any other).
            $rows = $asset->find(
                $asset->isEntityAssign()
                    ? getEntitiesRestrictCriteria($asset::getTable(), '', $entities_id, $asset->maybeRecursive())
                    : [],
                [],
                1
            );
            if ($rows === []) {
                continue;
            }
            (new Drill_Item())->add([
                'plugin_drillreport_drills_id' => $drillId,
                'itemtype'                     => $itemtype,
                'items_id'                     => (int) array_key_first($rows),
                'dr_target'                    => 'DR_DBSERVER2 / 192.168.230.87',
                'is_recovered'                 => 1,
                'recovery_order'               => 1,
                'comment'                     => 'Contoh — mewakili DBSERVER2 (CRM DB). Switchover Oracle Data Guard DC↔DRC, tidak ada gap. '
                                              . 'Tambahkan CRM_EOM/AM/GW/WEB1, Gapura, HQAPEX, Payment Engine secara manual (lihat docs/entering-a-real-drill.md).',
            ]);
            break;
        }

        return $drillId;
    }

    /**
     * Purge every [SAMPLE] drill of the user's entities (and its children)
     * plus the [SAMPLE] actions.
     *
     * @return int number of drills removed
     */
    public static function remove(): int
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => Drill::getTable(),
            'WHERE'  => ['name' => ['LIKE', self::MARKER . '%']] + getEntitiesRestrictCriteria(Drill::getTable()),
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        $drill = new Drill();
        foreach ($ids as $id) {
            $drill->delete(['id' => $id], true); // purge → Drill::cleanDBonPurge() drops children
        }
        return count($ids);
    }

    /**
     * Id of the reference row named $name the sample drill may use, created
     * when there is none: a scenario or category visible from the drill's
     * entity (its own, or a shared one of an ancestor), or a global test type.
     */
    private static function dropdownId(\CommonDropdown $dropdown, string $name, int $entities_id): int
    {
        if ($dropdown instanceof TestType) {
            $hit = $dropdown->find(['name' => $name]);
            if ($hit !== []) {
                return (int) array_key_first($hit);
            }
            $id = $dropdown->add(['name' => $name]);
            return $id ? (int) $id : 0;
        }

        /** @var Scenario|ActionCategory $dropdown */
        $hit = $dropdown::idByName($name, $entities_id);
        if ($hit > 0) {
            return $hit;
        }
        $id = $dropdown->add(['name' => $name, 'entities_id' => $entities_id, 'is_recursive' => 0]);
        return $id ? (int) $id : 0;
    }
}
