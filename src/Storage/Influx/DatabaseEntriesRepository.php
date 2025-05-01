<?php

namespace Laravel\Telescope\Storage\Influx;

use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use InfluxDB2\Client as InfluxClient;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use \InfluxDB2\Model\WritePrecision;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\IncomingEntry;

class DatabaseEntriesRepository implements Contract, PrunableRepository
{
    private InfluxClient $client;

    public function __construct(array $config)
    {
        $config['precision'] = WritePrecision::S;
        $this->client = new InfluxClient($config);
    }

    /**
     * Return an entry with the given ID.
     *
     * @param  mixed  $id
     * @return \Laravel\Telescope\EntryResult
     */
    public function find($id): EntryResult
    {
        $entry =  EntryModel::on($this->client)->whereUuid(
            $id
        )->firstOrFail();

        return new EntryResult(
            $entry['uuid'],
            $entry['sequence'],
            $entry['batch_id'],
            $entry['type'],
            $entry['family_hash'],
            $entry['content'],
            $entry['created_at'],
            $entry['tags']
        );
    }

    /**
     * Return all the entries of a given type.
     *
     * @param  string|null  $type
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return \Illuminate\Support\Collection|\Laravel\Telescope\EntryResult[]
     */
    public function get($type, EntryQueryOptions $options)
    {
        return EntryModel::on($this->client)
            ->withTelescopeOptions($type, $options)
            ->take($options->limit)
            ->get()
            ->map(
                fn($row, $index) => new EntryResult(
                    $row['uuid'],
                    $row['sequence'],
                    $row['batch_id'],
                    $row['type'],
                    $row['family_hash'],
                    $row['content'],
                    $row['created_at'],
                    $row['tags']
                )
            );
    }
    /**
     * Store the given entries.
     *
     * @param  \Illuminate\Support\Collection|\Laravel\Telescope\IncomingEntry[]  $entries
     * @return void
     */
    public function store(Collection $entries)
    {
        Log::info('store entries', ['entries' => $entries]);
        EntryModel::on($this->client)->storeEntries($entries);
        return;
    }

    /**
     * Store the given entry updates and return the failed updates.
     *
     * @param  \Illuminate\Support\Collection|\Laravel\Telescope\EntryUpdate[]  $updates
     * @return \Illuminate\Support\Collection|null
     */
    public function update(Collection $updates)
    {

        $failedUpdates = [];

        foreach ($updates as $update) {
            $entry = EntryModel::on($this->client)
                ->whereUuid($update->uuid)
                ->whereType($update->type)
                ->get()
                ->first();

            if (! $entry) {
                $failedUpdates[] = $update;
                continue;
            }

            $content = array_merge(
                $entry['content'] ?? [],
                $update->changes['content']
            );

            $newEntry = new IncomingEntry(content: $content, uuid: $update->uuid);
            $newEntry  =  $newEntry
                ->type($update->type)
                ->withFamilyHash($entry['family_hash'])
                ->batchId($entry['batch_id'])
                ->withRecordedAt(Carbon::parse($entry['_time']))
                ->tags($entry['tags']);

            EntryModel::on($this->client)
                ->storeEntries(Collection::make([$newEntry]));
        }

        return collect($failedUpdates);
    }

    /**
     * Load the monitored tags from storage.
     *
     * @return void
     */
    public function loadMonitoredTags() {}

    /**
     * Determine if any of the given tags are currently being monitored.
     *
     * @param  array  $tags
     * @return bool
     */
    public function isMonitoring(array $tags)
    {
        return false;
    }

    /**
     * Get the list of tags currently being monitored.
     *
     * @return array
     */
    public function monitoring()
    {
        return [];
    }

    /**
     * Begin monitoring the given list of tags.
     *
     * @param  array  $tags
     * @return void
     */
    public function monitor(array $tags)
    {
        return;
    }

    /**
     * Stop monitoring the given list of tags.
     *
     * @param  array  $tags
     * @return void
     */
    public function stopMonitoring(array $tags)
    {
        return [];
    }

    /**
     * Prune all of the entries older than the given date.
     *
     * @param  \DateTimeInterface  $before
     * @param  bool  $keepExceptions
     * @return int
     */
    public function prune(\DateTimeInterface $before, $keepExceptions)
    {
        $startDate = Carbon::createFromTimestampMs(0)->toDateTime();
        $endDate = Carbon::parse($before)->toDateTime();

        EntryModel::on($this->client)->prune(
            $startDate,
            $endDate,
        );
    }
}
