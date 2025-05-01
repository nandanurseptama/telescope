<?php

namespace Laravel\Telescope\Tests\Storage\Influx;

use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\Storage\Influx\DatabaseEntriesRepository;
use Laravel\Telescope\Tests\FeatureTestCase;

class DatabaseEntriesRepositoryTest extends FeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        SetupTest::insertSampleData();
    }

    protected function tearDown(): void
    {
        SetupTest::pruneSampleData();
        parent::tearDown();
    }

    public function test_find_entry_by_uuid()
    {
        $entry = SetupTest::getSampleIncomingEntry();

        $repository = new DatabaseEntriesRepository(
            config('telescope.storage.influx'),
        );

        $result = $repository->find($entry->uuid)->jsonSerialize();

        $this->assertNotNull($result);

        $this->assertSame($entry->uuid, $result['id']);
        $this->assertSame($entry->batchId, $result['batch_id']);
        $this->assertSame($entry->type, $result['type']);
        $this->assertSame($entry->content, $result['content']);
    }

    public function test_update()
    {
        $entry = SetupTest::getSampleIncomingEntry();

        $repository = new DatabaseEntriesRepository(
            config('telescope.storage.influx'),
        );

        $result = $repository->find($entry->uuid)->jsonSerialize();

        $failedUpdates = $repository->update(collect([
            new EntryUpdate($result['id'], $result['type'], ['content' => ['foo' => 'bar']]),
            new EntryUpdate('missing-id', $result['type'], ['content' => ['foo' => 'bar']]),
        ]));

        $this->assertCount(1, $failedUpdates);
        $this->assertSame('missing-id', $failedUpdates->first()->uuid);
    }
}
