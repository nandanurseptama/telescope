<?php

namespace Laravel\Telescope\Storage\Influx;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\EntityNotFoundException;
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

    private string $uuid;

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
            ->whereBeforeSequence($options->beforeSequence)
            ->whereTags($options->tag)
            ->orderBy("_time", true);
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


        $query = join(
            "\n|> ",
            [
                $query,
                "map(fn: (r) => ({ r with _field: \"field_\" + r._field }))",
                "pivot(rowKey: [\"_time\"], columnKey: [\"_field\"], valueColumn: \"_value\")",
                "group()",
                "map(fn: (r) => ({r with sequence : uint(v: r._time) / uint(v : 1000000)}))",
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

        $query = join("\n|>", [$query, "limit(n : $this->limit)"]);


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

        foreach ($tables as $table) {
            foreach ($table->records as $record) {

                $value = $record->values;

                $tags = [];
                $content = json_decode($value['field_content'], true);
                $familyHash = !key_exists('field_family_hash', $value) ? null : $value['field_family_hash'];

                $row = [
                    'type' => $value['type'],
                    'created_at' => Carbon::createFromTimestampMs($value['sequence']),
                    'sequence' => $value['sequence'],
                    'batch_id' => $value['field_batch_id'],
                    'uuid' => $value['field_uuid'],
                    'family_hash' => $familyHash,
                    'content' => $content === null ? [] : $content,
                    'tags' => $tags,
                    '_time' => $value['_time']
                ];

                foreach ($value as $key => $field) {
                    if ($key === 'result' || $key === 'table' || $key === 'sequence' || $key === 'type' || str_starts_with($key, '_') || str_starts_with($key, 'field_')) {
                        continue;
                    }

                    if ($field === null) {
                        continue;
                    }

                    array_push($tags, implode(":", [$key, $field]));
                }

                $row['tags'] = $tags;

                array_push($records, $row);
            }
        }


        $collection = Collection::make($records);

        return $collection;
    }

    /**
     * Get telescope records from influx
     *
     * @return array
     */
    public function firstOrFail()
    {
        return $this->take(1)->get()->firstOrFail();
    }

    /**
     * Filter entry by id
     *
     * if `$id` is null will ignore filter
     *
     * @param null|string $id entry id
     */
    public function whereUuid(?string $id): EntryModel
    {
        if (!$id) {
            return $this;
        }

        $this->uuid = $id;

        return $this->withFilter("filter(fn: (r) => r.field_uuid == \"$id\")");
    }

    /**
     * Filter entry by family hash
     *
     * if `$id` is null will ignore filter
     *
     * @param null|string $id entry id
     */
    public function whereFamilyHash(?string $familyHash): EntryModel
    {
        if (!$familyHash) {
            return $this;
        }

        return $this->withFilter("filter(fn: (r) => r.field_family_hash == \"$familyHash\")");
    }

    public function whereBatchId(?string $batchId): EntryModel
    {
        if (!$batchId) {
            return $this;
        }
        return $this->withFilter("filter(fn: (r) => r.field_batch_id == \"$batchId\")");
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

    public function whereBeforeSequence(?string $sequence): EntryModel
    {
        if (!$sequence) {
            return $this;
        }

        $sequence = intval($sequence);

        return $this->withFilter("filter(fn: (r) => r.sequence < $sequence)");
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

        return $this->withFilter("filter(fn: (r) => r[\"$tagKey\"] == \"$tagValue\")");
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

        $entries = $entries->map(function ($row) {;
            $point = Point::measurement('telescope_entries')
                ->addField('uuid', $row->uuid)
                ->addField('content', json_encode($row->content, JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE))
                ->addField('batch_id', $row->batchId)
                ->addField('family_hash', $row->familyHash)
                ->addTag('type', $row->type);

            if (count($row->tags) > 0) {
                foreach ($row->tags as $tag) {
                    $separated = explode(":", $tag);
                    $tagKey = $separated[0];
                    $tagValue = $separated[1];
                    $point = $point->addTag($tagKey, $tagValue);
                }
            }

            return $point->time(Carbon::parse($row->recordedAt)->getTimestampMs() * 1_000_000);
        });

        $writeApi->write($entries->toArray(), WritePrecision::NS);
    }
}
