<?php

namespace kak\geonames;


class UpdateDatabase extends AbstractGeoData
{
    private function stdout(string $message): void
    {
        var_dump($message);
    }

    private function download(string $url, string $zipFile): void
    {
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

        @unlink($zipFile);
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
                var_dump($result);
            }

            yield $result;
        }
        fclose($handle);
        @unlink($txtFile);
    }

    /**
     * @throws \JsonException
     */
    public function run(): void
    {
        $baseUrl = 'https://download.geonames.org/export/dump/';
        $tempDir = $this->dataBasePath;

        $alternativeNames = [];
        $languages = ['ru', 'en', 'fr', 'de', 'jp', 'zh', 'ko'];
        $presets = ['cities1000.zip', 'cities15000.zip', 'cities500.zip', 'cities5000.zip'];

        $urlAltName = $baseUrl . 'alternateNamesV2.zip';
        $zipAltName = $tempDir . 'alternateNamesV2.zip';
        $txtAltName = $tempDir . 'alternateNamesV2';

        $this->download($urlAltName, $zipAltName);
        $this->extract($zipAltName, $txtAltName);

        $altHeaders = [
            'id', 'geonameid', 'type', 'value', 'isPreferredName',
            'isShortName', 'isColloquial', 'isHistoric', 'from', 'to'
        ];

        foreach ($this->parsing($tempDir . 'alternateNamesV2/alternateNamesV2.txt', $altHeaders) as $altData) {
            $geonameid = $altData['geonameid'];
            $type = $altData['type'];
            $value = $altData['value'] ?? '';
            if (in_array($type, $languages, true)) {
                $alternativeNames[$geonameid]['names'][$type] = $value;
            }
        }

        $cityStorage = new GeoStorage($tempDir);
        $cityStorage->reset();

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
                $names = $alternativeNames[$city['geonameid']]['names'] ?? [];
                $city['names'] = $names;
                $city['name'] = $city['asciiname']?: $city['name'];
                $cityStorage->addCity($city);
            }
        }

        $cityStorage->finalize();
    }
}