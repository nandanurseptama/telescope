<?php

namespace Laravel\Telescope\Tests\Storage\Influx;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Storage\Influx\DatabaseEntriesRepository;

class SetupTest
{
    static string $sample_uuid = '00000000-0000-0000-0000-000000000000';

    public static function getSampleIncomingEntry()
    {
        $incoming =  new IncomingEntry(
            [
                'laravel' => 'telescope',
            ],
            SetupTest::$sample_uuid,
        );

        return $incoming
            ->batchId(SetupTest::$sample_uuid)
            ->type('test')
            ->tags([])
            ->withFamilyHash(null);
    }

    public static function insertSampleData()
    {
        $repository = new DatabaseEntriesRepository(
            config('telescope.storage.influx'),
        );

        $repository->store(Collection::make([SetupTest::getSampleIncomingEntry()]));
    }

    public static function pruneSampleData()
    {
        $repository = new DatabaseEntriesRepository(
            config('telescope.storage.influx'),
        );

        $repository->prune(Carbon::now()->addDays(1), false);
    }
}
