<?php

namespace kak\geonames;

class GeoStorage extends AbstractGeoData
{
    /** @var resource|null */
    private $dataStream = null;
    /** @var resource|null */
    private $tmpIndexIdStream = null;
    /** @var resource|null */
    private $tmpIndexLatStream = null;

    public function reset(): void
    {
        $this->closeStreams();
        @unlink($this->dataFile);
        @unlink($this->indexIdFile);
        @unlink($this->indexLatFile);
    }

    /**
     * Открывает файловые потоки для записи
     */
    public function open(): void
    {
        $this->dataStream = fopen($this->dataFile, 'wb');

        $this->tmpIndexIdStream = fopen($this->indexIdFile . '.tmp', 'w+b');
        $this->tmpIndexLatStream = fopen($this->indexLatFile . '.tmp', 'w+b');

        if (!$this->dataStream || !$this->tmpIndexIdStream || !$this->tmpIndexLatStream) {
            throw new \RuntimeException("Failed to open data or temporary index files.");
        }
    }

    /**
     * Add city to file
     * @throws \JsonException
     */
    public function addCity(array $city): void
    {
        if (!$this->dataStream) {
            $this->open();
        }

        fseek($this->dataStream, 0, SEEK_END);
        $offset = ftell($this->dataStream);

        $parentCityId = (int)($city['parent_city_id'] ?? 0);
        $geonameid = (int)$city['geonameid'];
        $lat = (float)$city['latitude'];
        $lon = (float)$city['longitude'];
        $countryCode = substr($city['country_code'] ?? '', 0, 2);
        $name = $city['name'] ?? '';

        $names = json_encode($city['names'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $nameLen = min(strlen($name), 255);
        $namesLen = min(strlen($names), 65535);

        $header = pack(
            self::PACK_DATA,
            $geonameid,
            $parentCityId,
            $lat,
            $lon,
            $countryCode,
            $nameLen,
            $namesLen
        );

        fwrite($this->dataStream, $header);
        if ($nameLen > 0) {
            fwrite($this->dataStream, $name, $nameLen);
        }
        if ($namesLen > 0) {
            fwrite($this->dataStream, $names, $namesLen);
        }

        fwrite($this->tmpIndexIdStream, pack(self::PACK_ID_DATA, $geonameid, $offset));
        fwrite($this->tmpIndexLatStream, pack(self::PACK_LAT_DATA, $lat, $lon, $offset));
    }

    /**
     * Sorts the temporary file by ID and saves the final index_id.bin
     */
    private function saveIndexId(): void
    {
        fseek($this->tmpIndexIdStream, 0, SEEK_END);
        $totalBytes = ftell($this->tmpIndexIdStream);
        $recordSize = 12;
        $count = $totalBytes / $recordSize;

        if ($count === 0) {
            return;
        }

        fseek($this->tmpIndexIdStream, 0, SEEK_SET);
        $raw = fread($this->tmpIndexIdStream, $totalBytes);


        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($raw, $i * $recordSize, $recordSize);
            $unpacked = unpack(self::UNPACK_ID_DATA, $chunk);
            $pairs[] = [$unpacked['id'], $unpacked['offset']];
        }

        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);

        $out = fopen($this->indexIdFile, 'wb');
        foreach ($pairs as $pair) {
            fwrite($out, pack(self::PACK_ID_DATA, $pair[0], $pair[1]));
        }
        fclose($out);
    }

    /**
     * Sorts the temporary file by Latitude and saves the final index_lat.bin
     */
    private function saveIndexLatFile(): void
    {
        fseek($this->tmpIndexLatStream, 0, SEEK_END);
        $totalBytes = ftell($this->tmpIndexLatStream);
        $recordSize = 16;
        $count = $totalBytes / $recordSize;

        if ($count === 0) {
            return;
        }

        fseek($this->tmpIndexLatStream, 0, SEEK_SET);
        $raw = fread($this->tmpIndexLatStream, $totalBytes);

        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($raw, $i * $recordSize, $recordSize);
            $unpacked = unpack(self::UNPACK_LAT_DATA, $chunk);
            $pairs[] = [
                $unpacked['latitude'],
                $unpacked['longitude'],
                $unpacked['offset']
            ];
        }

        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);

        $out = fopen($this->indexLatFile, 'wb');
        foreach ($pairs as $pair) {
            fwrite($out, pack(self::PACK_LAT_DATA, $pair[0], $pair[1], $pair[2]));
        }
        fclose($out);
    }

    public function finalize(): void
    {
        $this->closeStreams();

        $this->tmpIndexIdStream = fopen($this->indexIdFile . '.tmp', 'rb');
        $this->tmpIndexLatStream = fopen($this->indexLatFile . '.tmp', 'rb');

        $this->saveIndexId();
        $this->saveIndexLatFile();

        $this->closeStreams();

        @unlink($this->indexIdFile . '.tmp');
        @unlink($this->indexLatFile . '.tmp');
    }

    private function closeStreams(): void
    {
        if ($this->dataStream) { fclose($this->dataStream); $this->dataStream = null; }
        if ($this->tmpIndexIdStream) { fclose($this->tmpIndexIdStream); $this->tmpIndexIdStream = null; }
        if ($this->tmpIndexLatStream) { fclose($this->tmpIndexLatStream); $this->tmpIndexLatStream = null; }
    }

    public function __destruct()
    {
        $this->closeStreams();
    }
}
