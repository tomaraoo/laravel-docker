<?php

return [
    'app_port' => env('DOCKER_APP_PORT', 8080),
    'open_url' => env('DOCKER_OPEN_URL'),
    'db_image' => env('DOCKER_DB_IMAGE', 'mysql:8.0'),
    'db_host' => env('DOCKER_DB_HOST', 'db'),
    'host_uid' => env('HOST_UID'),
    'host_gid' => env('HOST_GID'),
    'host_extra_gid' => env('HOST_EXTRA_GID'),
    'code_path' => env('APP_CODE_PATH'),
];
