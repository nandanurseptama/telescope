<?php

namespace Laravel\Telescope\Storage\Influx;

use Illuminate\Support\Collection;
use InfluxDB2\Client as InfluxClient;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use JsonSerializable;

class EntryModel implements JsonSerializable
{

    private InfluxClient $client;

    private string $bucket;

    /**
     * Entry uuid
     *
     * @var string
     */
    public string $uuid;

    private function __construct(InfluxClient $client)
    {
        $this->client = $client;
    }


    static function on(InfluxClient $connection)
    {
        return new EntryModel($connection);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'uuid' => $this->uuid,
        ];
    }

    public function jsonDeserialize(?array $data): EntryModel
    {
        if (!$data) {
            return $this;
        }

        $this->uuid = $data['uuid'];

        return $this;
    }


    public function whereUuid(string $id): EntryModel
    {
        $query = "from(bucket: \"$this->bucket\")
	        |> filter(fn: (r) => r.uuid == \"$id\")";

        $queryApi = $this->client->createQueryApi();

        $result = $queryApi->query($query);


        return $this->jsonDeserialize($result);
    }

    /**
     * Store entries to InfluxDB
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

            return $point->time(microtime(true));
        });

        $writeApi->write($entries->toArray(), WritePrecision::S);
    }
}
