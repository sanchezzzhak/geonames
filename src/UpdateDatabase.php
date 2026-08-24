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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CityDownloader/1.0)');
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

        $adminMap = [];
        $processedCities = [];
        $languages = ['ru', 'en', 'fr', 'de', 'jp', 'zh', 'ko'];
        $presets = ['cities15000.zip', 'cities5000.zip', 'cities1000.zip', 'cities500.zip'];

        $urlAdminCodes =  $baseUrl . 'admin2Codes.txt';
        $adminCodesTmp =  $tempDir . 'admin2Codes.txt';
        $this->download($urlAdminCodes, $adminCodesTmp);

        $cityStorage = new GeoStorage($tempDir);
        $cityStorage->reset();

        $this->stdout("We read city presets, build a map of districts/regions and save the skeleton of cities ...");

        foreach ($presets as $preset) {
            $headers = [
                'geonameid', 'name', 'asciiname', 'alternatenames', 'latitude', 'longitude',
                'feature_class', 'feature_code', 'country_code', 'cc2', 'admin1_code',
                'admin2_code', 'admin3_code', 'admin4_code', 'population', 'elevation',
                'dem', 'timezone', 'modification_date'
            ];
            $url = $baseUrl . $preset;
            $zipFile = $tempDir . $preset;
            $txtFile = $tempDir . str_replace('.zip', '.txt', $preset);
            $this->download($url, $zipFile);
            $this->extract($zipFile, $tempDir);

            foreach ($this->parsing($txtFile, $headers) as $city) {
                $geonameid = $city['geonameid'];
                $featureClass = $city['feature_class'];
                $featureCode = $city['feature_code'];

                // Administrative Division Key (Country + Region + District)
                $adminKey = sprintf("%s.%s.%s", $city['country_code'], $city['admin1_code'], $city['admin2_code']);
                // If this is a full-fledged city (PPL, PPLA, etc.) and there is no main city for this district yet
                if ($featureClass === 'P' && $featureCode !== 'PPLX' && !isset($adminMap[$adminKey])) {
                    $adminMap[$adminKey] = [
                        'id' => $geonameid,
                        'name' => $city['asciiname'] ?: $city['name']
                    ];
                }

                $city['name'] = $city['asciiname']?: $city['name'];
                $city['names'] = [];
                $city['parent_city_id'] = '';
                $city['parent_city_name'] = '';

                $processedCities[$geonameid] = $city;
//                $cityStorage->addCity($city);
            }
        }

        $this->stdout("Linking districts with parent cities...");

        foreach ($processedCities as $geonameid => &$city) {
            $adminKey = sprintf("%s.%s.%s", $city['country_code'], $city['admin1_code'], $city['admin2_code']);

            if (isset($adminMap[$adminKey]) && $adminMap[$adminKey]['id'] !== $geonameid) {
                $city['parent_city_id'] = $adminMap[$adminKey]['id'];
                $city['parent_city_name'] = $adminMap[$adminKey]['name'];
            }
        }
        unset($city);

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

        $this->stdout("Streaming implementation of multilingual translations...");

        foreach ($this->parsing($txtAltNameFile, $altHeaders) as $altData) {
            $geonameid = $altData['geonameid'];
            if (isset($processedCities[$geonameid])) {
                $type = $altData['type'];
                $value = $altData['value'] ?? '';
                if (in_array($type, $languages, true)) {
                    $processedCities[$geonameid]['names'][$type] = $value;
                }
            }
        }


        $this->stdout("Writing data to GeoStorage...");

        foreach ($processedCities as $city) {
            $cityStorage->addCity($city);
        }

        $cityStorage->finalize();

        $this->removeTmpFile($tempDir . 'alternateNamesV2/alternateNamesV2.txt');
        $this->removeTmpFile($tempDir . 'alternateNamesV2/iso-languagecodes.txt');
       // @rmdir($txtAltNameFolder);

    }

}