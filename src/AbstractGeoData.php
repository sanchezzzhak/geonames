<?php

namespace kak\geonames;

abstract class AbstractGeoData
{
    // I=geonameid, I=parent_city_id, ff=lat/lon, a2=country, C=name_len, n=names_len
    // 4+4+4+4+2+1+2 = 21 byte
    public const PACK_DATA = 'IIffa2Cn';
    public const PACK_DATA_SIZE = 21;
    public const UNPACK_DATA = 'Igeonameid/Iparent_city_id/flatitude/flongitude/a2country_code/Cname_len/nnames_len';

    // 4 + 8 = 12 byte
    public const PACK_ID_DATA = 'IQ';
    public const UNPACK_ID_DATA = 'Iid/Qoffset';

    // 4 (float lat) + 4 (float lon) + 8 (offset) = 16 byte
    public const PACK_LAT_DATA = 'ffQ';
    public const UNPACK_LAT_DATA = 'flatitude/flongitude/Qoffset';

    protected ?string $dataBasePath;                    // database path storage or read
    protected string $dataFile = 'cities.bin';          // city database (binary)
    protected string $indexIdFile = 'index_id.bin';     // index for searching by ID (binary, sorted)
    protected string $indexLatFile = 'index_lat.bin';   // index for searching by coordinates (binary, sorted)

    public function __construct(?string $databasePath)
    {
        $this->dataBasePath = $databasePath ?? $_ENV['GEO_NAMES_DATABASE_PATH'] ?? null;

        if (empty($this->dataBasePath)) {
            throw new \RuntimeException("Not set path database.");
        }


        $dir = rtrim($this->dataBasePath, '/') . '/';
        $this->dataFile = $dir . $this->dataFile;
        $this->indexIdFile = $dir . $this->indexIdFile;
        $this->indexLatFile = $dir . $this->indexLatFile;
    }

    /**
     * We translate the name only into litinitsa
     * @param string $text
     * @return string|null
     */
    public function normalize(string $text): string|null
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        $translit = [
            'á|à|ä|â' => 'a', 'é|è|ë|ê' => 'e', 'í|ì|ï|î' => 'i',
            'ó|ò|ö|ô' => 'o', 'ú|ù|ü|û' => 'u', 'ñ' => 'n', 'ç' => 'c',
            'а|б|в|г|д|е|ё|ж|з|и|й|к|л|м|н|о|п|р|с|т|у|ф|х|ц|ч|ш|щ|ъ|ы|ь|э|ю|я' =>
                'a|b|v|g|d|e|yo|zh|z|i|y|k|l|m|n|o|p|r|s|t|u|f|h|ts|ch|sh|sch||y||e|yu|ya',
        ];

        foreach ($translit as $key => $value) {
            $text = preg_replace('/' . $key . '/ui', $value, $text);
        }

        return preg_replace('/[^a-z0-9]/u', '', $text);
    }
}