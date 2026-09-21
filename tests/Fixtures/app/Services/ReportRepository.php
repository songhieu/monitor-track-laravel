<?php

namespace MonitorTrack\Tests\Fixtures\App\Services;

use Illuminate\Database\Connection;

class ReportRepository
{
    /**
     * Reports a finished query with a given duration, as Connection::run()
     * does, so tests don't depend on the clock.
     */
    public function totals(Connection $db, float $ms): void
    {
        $db->logQuery("select sum(total) from orders where placed_at > '2026-01-01'", [], $ms);
    }
}
