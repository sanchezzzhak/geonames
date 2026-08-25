<?php
ini_set('memory_limit', '1G');

require __DIR__ . '/../vendor/autoload.php';

use kak\geonames\UpdateDatabase;

$service = new UpdateDatabase(
    __DIR__ . '/../data/'
);
$service->run();