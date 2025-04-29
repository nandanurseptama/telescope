<?php

namespace Laravel\Telescope\Storage\Influx;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use InfluxDB2\Client as InfluxClient;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use Laravel\Telescope\Storage\EntryQueryOptions;

class EntryModel
{

    private InfluxClient $client;

    private string $bucket;

    private array $filters;

    private array $sorts;

    private int $limit = 50;

    private int $offset = 0;

    private function __construct(InfluxClient $client)
    {
        $this->client = $client;
        $this->filters = [];
        $this->sorts = [];
        $this->bucket = $client->options['bucket'];
    }

    private function getDefaultTimerange()
    {
        if (!key_exists('default_timerange', $this->client->options)) {
            return config('telescope.storage.influx.default_timerange');
        }

        return $this->client->options['default_timerange'];
    }

    /**
     * Create new instance of EntryModel with influx connection
     *
     * @param InfluxClient $connection is influx connection
     */
    public static function on(InfluxClient $connection)
    {
        return new EntryModel($connection);
    }

    /**
     * Add filter to query
     *
     * @param string $filter is influx filter
     */
    private function withFilter(string $filter): EntryModel
    {
        array_push($this->filters, $filter);
        return $this;
    }

    /**
     * Add sort
     *
     * @param string $column column name
     * @param bool $isDesc is descending order
     */
    public function orderBy(string $column, bool $isDesc = false): EntryModel
    {
        $desc = $isDesc ? ", desc: true" : "";
        $querySort = "sort(columns : [\"$column\"]$desc)";

        array_push($this->sorts, $querySort);
        return $this;
    }

    /**
     * Add limit
     *
     * @param int $limit num of row data
     */
    public function take(int $limit): EntryModel
    {
        $this->limit = $limit;
        return $this;
    }

    public function withTelescopeOptions(?string $type, EntryQueryOptions $options): EntryModel
    {
        return $this
            ->whereType($type)
            ->whereBatchId($options->batchId)
            ->whereFamilyHash($options->familyHash)
            ->whereUuid($options->uuids)
            ->orderBy("_time", true)
            ->offset($options->beforeSequence);
    }

    private function buildQuery(): string
    {
        $query = "from(bucket: \"$this->bucket\")";
        $timerange = $this->getDefaultTimerange();
        $query = join(
            "\n|> ",
            [
                $query,
                "range(start: $timerange)",
                "filter(fn: (r) => r._measurement == \"telescope_entries\")",
            ]
        );

        if (count($this->filters) > 0) {
            $filters = join("\n|> ", $this->filters);
            $query = join("\n|> ", [$query, $filters]);
        }

        if (count($this->sorts) > 0) {
            $sorts = join("\n|> ", $this->sorts);
            $query = join("\n|> ", [$query, $sorts]);
        }

        $offset = "";

        if ($this->offset > 0) {
            $offset = ",offset: " . $this->offset + 1;
        }

        $query = join(
            "\n|> ",
            [
                $query,
                "limit(n : $this->limit $offset)",
            ]
        );

        return $query;
    }

    /**
     * Get telescope records from influx
     */
    public function get()
    {
        $query = $this->buildQuery();

        $queryApi = $this->client->createQueryApi();
        $tables = $queryApi->query($query);

        $records = [];
        $rowPlaceholder = [
            'family_hash' => null,
            'type' => null,
            'batch_id' => null,
            'uuid' => null,
            'created_at' => null,
            'content' => [],
            'sequence' => null,
            'tags' => [],
        ];

        foreach ($tables as $table) {
            foreach ($table->records as $rowNum => $record) {
                $sequence = $rowNum + ($this->offset > 0 ? $this->offset + 1 : 0);
                // because we will have multiple fields at the same second in time, we need to merge the data into a single array after we query it out
                $row = key_exists($rowNum, $records) ? $records[$rowNum] : $rowPlaceholder;
                $field = $record->getField();

                $value = $field === 'content' ? json_decode($record->getValue(), true) : $record->getValue();

                $records[$rowNum] = array_merge(
                    $row,
                    [
                        $field => $value,
                        'type' => $record['type'],
                        'created_at' => Carbon::parse($record->getTime()),
                        'sequence' => $sequence,
                    ]
                );
            }
        }


        $collection = Collection::make($records);

        return $collection;
    }

    public function whereUuid(?string $id): EntryModel
    {
        if (!$id) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.uuid == \"$id\")");
    }

    public function whereFamilyHash(?string $familyHash): EntryModel
    {
        if (!$familyHash) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.family_hash == \"$familyHash\")");
    }

    public function whereBatchId(?string $batchId): EntryModel
    {
        if (!$batchId) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.batch_id == \"$batchId\")");
    }

    public function whereType(?string $type): EntryModel
    {
        if (!$type) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.type == \"$type\")");
    }

    public function whereTags(?string $tag): EntryModel
    {
        if (!$tag) {
            return $this;
        }

        $tags = collect(explode(',', $tag))->map(fn($tag) => trim($tag));

        if ($tags->isEmpty()) {
            return $this;
        }


        return $tags->reduce(function ($query, $tag, $key) {
            return $query->whereTag($tag);
        }, $this);
    }

    public function whereTag(?string $tag)
    {
        if (!$tag) {
            return $this;
        }

        $separated = collect(explode(":", $tag));
        $tagKey = $separated->first();
        $tagValue = $separated->last();

        if (!$tagKey || !$tagValue) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.$tagKey == \"$tagValue\")");
    }

    public function offset(mixed $offset): EntryModel
    {
        if (!$offset) {
            return $this;
        }

        $this->offset = intval($offset);

        return $this;
    }

    /**
     * Store entries to InfluxDB
     *
     * @param \Illuminate\Support\Collection $entries
     */
    public function storeEntries(Collection $entries)
    {
        if ($entries->isEmpty()) {
            return;
        }

        $writeApi = $this->client->createWriteApi();

        $entries = $entries->map(function ($row) {
            $point = Point::measurement('telescope_entries')
                ->addField('uuid', $row->uuid)
                ->addField('content', json_encode($row->content))
                ->addField('batch_id', $row->batchId)
                ->addField('family_hash', $row->familyHash)
                ->addTag('type', $row->type);

            if (count($row->tags) > 0) {
                foreach ($row->tags as $tagKey => $tagValue) {
                    $point = $point->addTag($tagKey, $tagValue);
                }
            }

            return $point->time(Carbon::now()->getTimestampMs());
        });

        $writeApi->write($entries->toArray(), WritePrecision::MS);
    }
}
