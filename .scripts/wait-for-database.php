<?php

declare(strict_types=1);

/*
 * Waits until the database of TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL answers `SELECT 1` (the real-database tests, `--group database`).
 * Exits 1 after about two minutes.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = getenv('TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "TRANSLATION_YAML_TOOLS_TEST_DATABASE_URL is not set.\n");
    exit(1);
}

$params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
for ($i = 0; $i < 60; ++$i) {
    try {
        DriverManager::getConnection($params)->executeQuery('SELECT 1');
        echo "Database ready.\n";
        exit(0);
    } catch (Throwable) {
        sleep(2);
    }
}

fwrite(STDERR, "Database not reachable.\n");
exit(1);
