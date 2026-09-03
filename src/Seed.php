<?php

namespace GlpiPlugin\Drillreport;

/**
 * Install-time seed data. Idempotent — skips values that already exist.
 */
class Seed
{
    public static function run(): void
    {
        $tt = new TestType();
        foreach (TestType::SEED as $name) {
            if ($tt->find(['name' => $name]) === []) {
                $tt->add(['name' => $name, 'entities_id' => 0]);
            }
        }
    }
}
