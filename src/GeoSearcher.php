<?php

namespace kak\geonames;

class GeoSearcher extends AbstractGeoData
{
    /** @var resource|null Дескриптор файла городов удерживается для быстродействия */
    private $dataFileHandle = null;

    private function readCityAt($offset): ?array
    {
        if ($this->dataFileHandle === null) {
            $this->dataFileHandle = fopen($this->dataFile, 'rb');
            if (!$this->dataFileHandle) {
                return null;
            }
        }

        fseek($this->dataFileHandle, $offset);

        $headerData = fread($this->dataFileHandle, self::PACK_DATA_SIZE);
        if (strlen($headerData) !== self::PACK_DATA_SIZE) {
            return null;
        }

        $unpacked = unpack(self::UNPACK_DATA, $headerData);
        $countryCode = trim($unpacked['country_code'], "\0");

        $name = '';
        if ($unpacked['name_len'] > 0) {
            $name = fread($this->dataFileHandle, $unpacked['name_len']);
            $name = rtrim($name, "\0");
            if (!mb_check_encoding($name, 'UTF-8')) {
                $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
            }
        }

        $names = [];
        if ($unpacked['names_len'] > 0) {
            $namesJson = fread($this->dataFileHandle, $unpacked['names_len']);
            $namesJson = rtrim($namesJson, "\0");
            $names = json_decode($namesJson, true) ?: [];
        }

        if (!array_key_exists('en', $names)) {
            $names['en'] = $name;
        }

        return [
            'geonameid' => (string)$unpacked['geonameid'],
            'name' => $name,
            'latitude' => (float)$unpacked['latitude'],
            'longitude' => (float)$unpacked['longitude'],
            'country_code' => $countryCode,
            'names' => $names
        ];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            sin($dLon / 2) * sin($dLon / 2) *
            cos($lat1) * cos($lat2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c;
    }

    private function kmToDegrees(float $km, float $lat): array
    {
        $latDegrees = $km / 111.32;
        $lonDegrees = $km / (111.32 * cos(deg2rad($lat)));
        return [$latDegrees, $lonDegrees];
    }

    public function findByCoords(float $lat, float $lon, float $radiusKm = 10): array
    {
        [$latDelta, $lonDelta] = $this->kmToDegrees($radiusKm, $lat);

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLon = $lon - $lonDelta;
        $maxLon = $lon + $lonDelta;

        $cities = $this->findByCoordsBox($minLat, $maxLat, $minLon, $maxLon);
        $results = [];

        foreach ($cities as $city) {
            $d = $this->haversine($lat, $lon, $city['latitude'], $city['longitude']);
            if ($d <= $radiusKm) {
                $city['distance_km'] = round($d, 3);
                $results[] = $city;
            }
        }

        usort($results, static fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);

        return $results;
    }

    public function findByCoordsBox(float $minLat, float $maxLat, float $minLon, float $maxLon): array
    {
        $precision = 5;
        $minLat = round($minLat, $precision);
        $maxLat = round($maxLat, $precision);
        $minLon = round($minLon, $precision);
        $maxLon = round($maxLon, $precision);

        $fp = fopen($this->indexLatFile, 'rb');
        if (!$fp) {
            return [];
        }

        $filesize = filesize($this->indexLatFile);
        $recordSize = 16;
        $n = $filesize / $recordSize;

        $results = [];

        $left = 0;
        $right = $n - 1;
        $start = $n;

        while ($left <= $right) {
            $mid = $left + (int)(($right - $left) / 2);
            fseek($fp, $mid * $recordSize);
            $data = fread($fp, $recordSize);
            $unpacked = unpack(self::UNPACK_LAT_DATA, $data);
            $lat = round($unpacked['latitude'], $precision);
            if ($lat >= $minLat) {
                $start = $mid;
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        $left = 0;
        $right = $n - 1;
        $end = $n;

        while ($left <= $right) {
            $mid = $left + (int)(($right - $left) / 2);
            fseek($fp, $mid * $recordSize);
            $data = fread($fp, $recordSize);
            $unpacked = unpack(self::UNPACK_LAT_DATA, $data);
            $lat = round($unpacked['latitude'], $precision);

            if ($lat > $maxLat) {
                $end = $mid;
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        // Hash table instead of in_array to protect against O(N^2)
        $uniqueIds = [];

        for ($i = $start; $i < $end; $i++) {
            fseek($fp, $i * $recordSize);
            $data = fread($fp, $recordSize);
            $unpacked = unpack(self::UNPACK_LAT_DATA, $data);
            $lat = round($unpacked['latitude'], $precision);
            $lon = round($unpacked['longitude'], $precision);
            $offset = $unpacked['offset'];

            if ($lon >= $minLon && $lon <= $maxLon) {
                $city = $this->readCityAt($offset);
                if ($city) {
                    $id = $city['geonameid'];
                    if (!isset($uniqueIds[$id])) {
                        $uniqueIds[$id] = true;
                        $results[] = $city;
                    }
                }
            }
        }
        fclose($fp);

        return $results;
    }

    public function findById(int|string $id): ?array
    {
        $id = (int)$id;
        $fp = fopen($this->indexIdFile, 'rb');

        if (!$fp) {
            return null;
        }

        $filesize = filesize($this->indexIdFile);
        $recordSize = 12;
        $left = 0;
        $right = ($filesize / $recordSize) - 1;

        while ($left <= $right) {
            $mid = $left + (int)(($right - $left) / 2);
            $pos = $mid * $recordSize;

            fseek($fp, $pos);
            $data = fread($fp, $recordSize);

            if (strlen($data) !== $recordSize) {
                break;
            }

            $unpacked = unpack(self::UNPACK_ID_DATA, $data);
            if ($unpacked['id'] === $id) {
                fclose($fp);
                return $this->readCityAt($unpacked['offset']);
            }

            if ($unpacked['id'] < $id) {
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        fclose($fp);
        return null;
    }

    public function __destruct()
    {
        if ($this->dataFileHandle !== null) {
            fclose($this->dataFileHandle);
        }
    }
}
