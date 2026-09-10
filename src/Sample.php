<?php

namespace GlpiPlugin\Drillreport;

/**
 * One fully-worked demo drill, loadable / removable from the Setup screen so a
 * fresh install has a concrete reference. Every row's name is prefixed with
 * {@see self::MARKER} so it is easy to spot and remove.
 *
 * Mirrors the worked example in docs/drillreport-konsep-awal.md §6.6.
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
            'WHERE'  => ['name' => ['LIKE', self::MARKER . '%'], 'is_deleted' => 0],
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

        $scenarioId = self::dropdownId(new Scenario(), 'Failover CRM ke DRC', $entities_id);
        $testTypeId = self::dropdownId(new TestType(), 'Full failover', $entities_id);
        $catId      = self::dropdownId(new ActionCategory(), 'Backup & recovery', $entities_id);

        $drill = new Drill();
        $drillId = (int) $drill->add([
            'entities_id'                     => $entities_id,
            'name'                            => self::MARKER . 'DR Drill 2026 — Failover CRM ke DRC',
            'date_drill'                      => date('Y') . '-08-09',
            'declaration_time'                => date('Y') . '-08-09 08:12:00',
            'recovery_time'                   => date('Y') . '-08-09 09:47:00',
            'validation_time'                 => date('Y') . '-08-09 10:35:00',
            'plugin_drillreport_scenarios_id' => $scenarioId,
            'plugin_drillreport_testtypes_id' => $testTypeId,
            'groups_id'                       => 0,
            'users_id_tech'                   => (int) ($_SESSION['glpiID'] ?? 0),
            'objectives'                      => "1. Buktikan CRM bisa online di DRC dalam RTO 60 menit.\n"
                                               . "2. Validasi integritas data CRM pasca-failover (RPO 15 menit).\n"
                                               . "3. Uji runbook failover tanpa bantuan vendor.",
            'scope_summary'                   => 'Failover terkontrol aplikasi CRM (web + database) dari DC HQ ke DRC. '
                                               . 'Sistem lain tidak disentuh. Skenario: kehilangan total DC HQ.',
            'rto_target_minutes'              => 60,
            'rto_actual_minutes'              => 95,
            'rpo_target_minutes'              => 15,
            'rpo_actual_minutes'              => 5,
            'data_loss_summary'               => 'RPO tercapai — kehilangan data ~5 menit (transaksi in-flight), '
                                               . 'dalam batas toleransi 15 menit.',
            'overall_result'                  => 'fail',
            'executive_summary'               => 'Failover CRM berhasil secara fungsional (aplikasi online, data konsisten) '
                                               . 'namun RTO MELESET: 95 menit vs target 60 menit. Penyebab utama: tidak ada '
                                               . 'standby database di DRC sehingga restore full backup 240 GB menjadi jalur kritis. '
                                               . '1 temuan High + 2 Medium; rencana perbaikan ditargetkan selesai Q4 2026.',
            'lessons_learned'                 => 'Runbook failover cukup akurat. Bottleneck ada di infrastruktur data, bukan prosedur. '
                                               . 'Perlu latihan restore terjadwal, bukan hanya saat drill tahunan.',
            'status'                          => 'submitted',
        ]);

        if ($drillId <= 0) {
            return 0;
        }

        // ---- Objectives / activities (with time budget + actual timing) --
        $y = date('Y');
        foreach ([
            ['CRM online di DRC dalam RTO 60 menit', 60, "{$y}-08-09 08:12:00", "{$y}-08-09 09:47:00", 'not_met', 'Online 09:47; disaster dideklarasi 08:12 → 95 menit (melewati target).'],
            ['Integritas data CRM pasca-failover (RPO 15 menit)', 0, null, null, 'met', 'Kehilangan data ~5 menit, dalam toleransi 15 menit.'],
            ['Eksekusi runbook failover tanpa vendor', 0, "{$y}-08-09 08:12:00", "{$y}-08-09 09:35:00", 'met', 'Seluruh langkah dijalankan tim internal.'],
        ] as [$name, $target, $start, $end, $result, $comment]) {
            (new Objective())->add([
                'plugin_drillreport_drills_id' => $drillId,
                'name'                         => $name,
                'target_minutes'               => $target,
                'start_time'                   => $start ?? 'NULL',
                'end_time'                     => $end ?? 'NULL',
                'result'                       => $result,
                'comment'                      => $comment,
            ]);
        }

        // ---- Improvement actions -------------------------------------
        $actions = [
            [
                'name'           => 'Restore database CRM melebihi RTO — 95 menit vs target 60 menit',
                'category'       => $catId,
                'severity'       => 'high',
                'content'        => 'Saat failover, restore full backup DB CRM (240 GB) ke server DRC memakan 95 menit. '
                                  . 'RTO target CRM = 60 menit. Aplikasi baru online 09:47; disaster dideklarasi 08:12.',
                'cause'          => 'Backup CRM hanya full daily, tanpa incremental. Link replikasi DC→DRC 1 Gbps shared, '
                                  . 'throughput efektif ~35 MB/s di jam kerja. Tidak ada standby DB yang sudah ter-restore di DRC.',
                'recommendation' => '1) Aktifkan streaming replication PostgreSQL DC→DRC untuk CRM (standby hangat, restore <5 menit). '
                                  . '2) Jika tidak feasible: jadwalkan restore mingguan ke DRC + apply incremental saat drill. '
                                  . '3) Usulkan link replikasi CRM dedicated 10 Gbps (capex Q4).',
                'due'            => date('Y') . '-11-30',
            ],
            [
                'name'           => 'DNS internal CRM belum ter-failover otomatis',
                'category'       => self::dropdownId(new ActionCategory(), 'Network & connectivity', $entities_id),
                'severity'       => 'medium',
                'content'        => 'Record DNS crm.internal masih menunjuk IP DC HQ. Diubah manual oleh tim jaringan (12 menit).',
                'cause'          => 'Tidak ada health-check + DNS failover untuk zona internal.',
                'recommendation' => 'Pasang DNS failover (mis. keepalived + health check) untuk record layanan kritis, atau '
                                  . 'skrip switch DNS yang jadi bagian runbook.',
                'due'            => date('Y') . '-10-31',
            ],
            [
                'name'           => 'Kontak eskalasi di runbook sudah usang',
                'category'       => self::dropdownId(new ActionCategory(), 'Documentation', $entities_id),
                'severity'       => 'low',
                'content'        => '2 dari 6 nomor kontak pada runbook tidak aktif; PIC storage sudah pindah tim.',
                'cause'          => 'Runbook tidak masuk siklus review berkala.',
                'recommendation' => 'Tambahkan review runbook DR ke kalender Q1 tiap tahun; simpan kontak di satu sumber (mis. GLPI).',
                'due'            => date('Y') . '-09-30',
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
                'date_due'                               => $a['due'],
            ]);
        }

        // ---- One system in scope, if an asset exists ---------------
        foreach (Drill_Item::getLinkedAssetItemtypes() as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }
            $asset = new $itemtype();
            $rows = $asset->find([], [], 1);
            if ($rows === []) {
                continue;
            }
            (new Drill_Item())->add([
                'plugin_drillreport_drills_id' => $drillId,
                'itemtype'                     => $itemtype,
                'items_id'                     => (int) array_key_first($rows),
                'rto_target_minutes'           => 60,
                'rto_actual_minutes'           => 95,
                'rpo_target_minutes'           => 15,
                'rpo_actual_minutes'           => 5,
                'is_recovered'                 => 1,
                'recovery_order'               => 1,
                'comment'                     => 'Aset contoh yang ditautkan otomatis oleh sample data.',
            ]);
            break;
        }

        return $drillId;
    }

    /**
     * Purge every [SAMPLE] drill (and its children) plus the [SAMPLE] actions.
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
            'WHERE'  => ['name' => ['LIKE', self::MARKER . '%']],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        $drill = new Drill();
        foreach ($ids as $id) {
            $drill->delete(['id' => $id], true); // purge → Drill::cleanDBonPurge() drops children
        }
        return count($ids);
    }

    private static function dropdownId(\CommonDropdown $dropdown, string $name, int $entities_id): int
    {
        $hit = $dropdown->find(['name' => $name]);
        if ($hit !== []) {
            return (int) array_key_first($hit);
        }
        $id = $dropdown->add(['name' => $name, 'entities_id' => $entities_id]);
        return $id ? (int) $id : 0;
    }
}
