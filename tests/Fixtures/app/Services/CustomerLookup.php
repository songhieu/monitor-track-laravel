<?php

namespace MonitorTrack\Tests\Fixtures\App\Services;

use Illuminate\Support\Facades\DB;

class CustomerLookup
{
    /**
     * One query per id.
     *
     * @param  list<int>  $ids
     * @return list<string|null>
     */
    public function names(array $ids): array
    {
        $names = [];
        foreach ($ids as $id) {
            $names[] = DB::table('customers')->where('id', $id)->value('name');
        }

        return $names;
    }
}
