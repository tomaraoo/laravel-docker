<?php

declare(strict_types=1);

use Rft\LaravelDocker\Support\EnvFileParser;

it('parses quoted, exported, interpolated, and inline-commented dotenv entries', function () {
    $parser = new EnvFileParser;

    $parsed = $parser->parseString(<<<'ENV'
# Comment line
APP_NAME="My Portal"
export APP_ENV=local
DB_PASSWORD='p@ss#word'
DOCKER_APP_PORT=8090 # local web port
DOCKER_OPEN_URL="http://localhost:${DOCKER_APP_PORT}"
ENV);

    expect($parsed)->toBe([
        'APP_NAME' => 'My Portal',
        'APP_ENV' => 'local',
        'DB_PASSWORD' => 'p@ss#word',
        'DOCKER_APP_PORT' => '8090',
        'DOCKER_OPEN_URL' => 'http://localhost:8090',
    ]);
});
