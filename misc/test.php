<?php

ini_set('memory_limit', '1G');

require __DIR__ . '/../vendor/autoload.php';

use kak\geonames\GeoSearcher;

$geo = new GeoSearcher(__DIR__ . '/../data/');

var_dump($geo->findByCoords(40.6892, -74.0445, 10));