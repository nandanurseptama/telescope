<?php

namespace Laravel\Telescope\Storage\Influx;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Laravel\Telescope\Contracts\EntriesRepository as Contract;
use InfluxDB2\Client as InfluxClient;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use \InfluxDB2\Model\WritePrecision;

class DatabaseEntriesRepository implements Contract
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
        return new EntryResult('', '', '', '', '', [], Carbon::now(), []);
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
        return [];
    }
    /**
     * Store the given entries.
     *
     * @param  \Illuminate\Support\Collection|\Laravel\Telescope\IncomingEntry[]  $entries
     * @return void
     */
    public function store(Collection $entries)
    {
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
        return null;
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
}
