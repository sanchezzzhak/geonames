<?php

namespace kak\geonames;


class UpdateDatabase extends AbstractGeoData
{
    private function stdout(string $message): void
    {
       // var_dump($message);
    }

    private function download(string $url, string $zipFile): void
    {
        if (is_file($zipFile)) {
            return;
        }

        $this->stdout("Download file ... $zipFile");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CityDownloader/1.0)');
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        if ($data === false) {
            $curlErrorNo = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL Error ($curlErrorNo): $curlError");
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Error download: HTTP $httpCode");
        }

        file_put_contents($zipFile, $data);
        $this->stdout("File downloaded: $zipFile");
    }

    private function extract(string $zipFile, string $tempDir): void
    {
        $this->stdout("Unpacking archive ...");
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException("Failed to open ZIP archive.");
        }

        $zip->extractTo($tempDir);
        $zip->close();

        $this->removeTmpFile($zipFile);
    }

    private function removeTmpFile(string $file): void
    {
        if (file_exists($file)) {
            //@unlink($file);
        }
    }

    private function parsing(string $txtFile, array $headers): \Generator
    {
        if (!file_exists($txtFile)) {
            throw new \RuntimeException("Файл данных не найден: $txtFile\n");
        }

        $this->stdout("Reading and parsing data ...");

        $i = 0;
        $handle = fopen($txtFile, 'rb');

        if (!$handle) {
            throw new \RuntimeException("Не удалось открыть файл данных.\n");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $row = explode("\t", $line);
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $result = array_combine($headers, $row);

            if ($i < 5) {
                $i++;
               // var_dump($result);
            }

            yield $result;
        }
        fclose($handle);

        $this->removeTmpFile($txtFile);
    }

    /**
     * @throws \JsonException
     */
    public function run(): void
    {
        $baseUrl = 'https://download.geonames.org/export/dump/';
        $tempDir = $this->dataBasePath;

        $languages = ['ru', 'en', 'fr', 'de', 'jp', 'zh', 'ko'];
        $presets = ['cities500.zip'];

        $urlAdminCodes = $baseUrl . 'admin2Codes.txt';
        $adminCodesTmp = $tempDir . 'admin2Codes.txt';
        $this->download($urlAdminCodes, $adminCodesTmp);

        $urlAdminCodes1 = $baseUrl . 'admin1CodesASCII.txt';
        $adminCodesTmp1 = $tempDir . 'admin1CodesASCII.txt';
        $this->download($urlAdminCodes1, $adminCodesTmp1);

        $headers = [
            'geonameid', 'name', 'asciiname', 'alternatenames', 'latitude', 'longitude',
            'feature_class', 'feature_code', 'country_code', 'cc2', 'admin1_code',
            'admin2_code', 'admin3_code', 'admin4_code', 'population', 'elevation',
            'dem', 'timezone', 'modification_date'
        ];

        $cityStorage = new GeoStorage($tempDir);
        $cityStorage->reset();

        $districtMap = [];
        $stateMap = [];
        $cityMap = [];
        $allowedIds = [];
        $virtualNodes = []; // Storage for administrative nodes missing in cities500

        $this->stdout("Parsing official admin1 state/region codes...");
        foreach ($this->parsing($adminCodesTmp1, ['key', 'name', 'asciiname', 'geonameid']) as $item) {

        }


        $this->stdout("Parsing official admin2 district codes...");
        foreach ($this->parsing($adminCodesTmp1, ['key', 'name', 'asciiname', 'geonameid']) as $item) {

        }
                $this->stdout("Scanning cities and building parent city map...");

        foreach ($presets as $preset) {
            $url = $baseUrl . $preset;
            $zipFile = $tempDir . $preset;
            $txtFile = $tempDir . str_replace('.zip', '.txt', $preset);

            $this->download($url, $zipFile);
            $this->extract($zipFile, $tempDir);

            foreach ($this->parsing($txtFile, $headers) as $city) {
                $geonameid = (int)$city['geonameid'];
                $allowedIds[$geonameid] = true;

                // If the real city details are available, remove it from the empty virtual nodes list
                unset($virtualNodes[$geonameid]);

                $fClass = $city['feature_class'] ?? '';
                $fCode = $city['feature_code'] ?? '';
                $population = (int)($city['population'] ?? 0);
                $cityName = $city['asciiname'] ?: $city['name'];

                $adminKey = sprintf("%s.%s.%s", $city['country_code'], $city['admin1_code'], $city['admin2_code']);
                $isCity = ($fClass === 'P' && $fCode !== 'PPLX') ? 1 : 0;

                if ($isCity === 1) {
                    $currentPriority = 0;
                    if ($fCode === 'PPLC') $currentPriority = 4;
                    elseif ($fCode === 'PPLA') $currentPriority = 3;
                    elseif ($fCode === 'PPLA2') $currentPriority = 2;
                    elseif ($fCode === 'PPL') $currentPriority = 1;

                    if (!isset($cityMap[$adminKey])) {
                        $cityMap[$adminKey] = [
                            'id' => $geonameid,
                            'priority' => $currentPriority,
                            'population' => $population
                        ];
                    } else {
                        $existing = $cityMap[$adminKey];
                        if ($currentPriority > $existing['priority'] ||
                            ($currentPriority === $existing['priority'] && $population > $existing['population'])) {
                            $cityMap[$adminKey] = [
                                'id' => $geonameid,
                                'priority' => $currentPriority,
                                'population' => $population
                            ];
                        }
                    }
                }
            }
        }


        // Process translations (alternateNamesV2 750MB zip / 1.5GB txt)
        $urlAltName = $baseUrl . 'alternateNamesV2.zip';
        $zipAltName = $tempDir . 'alternateNamesV2.zip';
        $txtAltNameFolder = $tempDir . 'alternateNamesV2';
        $txtAltNameFile = $txtAltNameFolder . '/alternateNamesV2.txt';

        $this->download($urlAltName, $zipAltName);
        $this->extract($zipAltName, $txtAltNameFolder);

        $altHeaders = [
            'id', 'geonameid', 'type', 'value', 'isPreferredName',
            'isShortName', 'isColloquial', 'isHistoric', 'from', 'to'
        ];

        $this->stdout("Stream filtering translations to temporary index...");
        $translations = [];

        foreach ($this->parsing($txtAltNameFile, $altHeaders) as $altData) {
            $geonameid = (int)$altData['geonameid'];
            $type = $altData['type'] ?? '';

            // We use the lightweight allowedIds map to filter out irrelevant rows instantly
            if (isset($allowedIds[$geonameid]) && in_array($type, $languages, true)) {
                $val = $altData['value'] ?? '';
                $translations[$geonameid][$type] = $val;
            }
        }

        // Free memory from the ID map since it is no longer required
        unset($allowedIds);

        $this->stdout("Final processing and compiling records into GeoStorage...");

        foreach ($presets as $preset) {
            $txtFile = $tempDir . str_replace('.zip', '.txt', $preset);

            foreach ($this->parsing($txtFile, $headers) as $city) {
                $geonameid = (int)$city['geonameid'];
                $adminKey = sprintf("%s.%s.%s", $city['country_code'], $city['admin1_code'], $city['admin2_code']);
                $stateKey = sprintf("%s.%s", $city['country_code'], $city['admin1_code']);

                $city['name'] = $city['asciiname'] ?: $city['name'];
                $city['names'] = $translations[$geonameid] ?? [];

                $parentCityId = isset($cityMap[$adminKey]) ? $cityMap[$adminKey]['id'] : 0;
                $city['parent_city_id'] = ($parentCityId === $geonameid) ? 0 : $parentCityId;

                $officialDistrictId = $districtMap[$adminKey] ?? 0;
                $city['district_id'] = ($officialDistrictId === $geonameid) ? 0 : $officialDistrictId;

                $officialStateId = $stateMap[$stateKey] ?? 0;
                $city['state_id'] = ($officialStateId === $geonameid) ? 0 : $officialStateId;

                $cityStorage->addCity($city);
            }-
            $this->removeTmpFile($txtFile);
        }

        $this->stdout("Appending missing regional administrative nodes...");
        foreach ($virtualNodes as $geonameid => $vNode) {
            $stateKey = sprintf("%s.%s", $vNode['country_code'], $vNode['admin1_code']);

            $vNode['names'] = $translations[$geonameid] ?? [];
            $vNode['parent_city_id'] = 0;
            $vNode['district_id'] = 0;

            // A state cannot be its own parent state reference
            $officialStateId = $stateMap[$stateKey] ?? 0;
            $vNode['state_id'] = ($officialStateId === $geonameid) ? 0 : $officialStateId;

            $cityStorage->addCity($vNode);
        }

        $cityStorage->finalize();

        $this->removeTmpFile($adminCodesTmp);
        $this->removeTmpFile($adminCodesTmp1);
        $this->removeTmpFile($txtAltNameFile);
        $this->removeTmpFile($tempDir . 'alternateNamesV2/iso-languagecodes.txt');

    }

}