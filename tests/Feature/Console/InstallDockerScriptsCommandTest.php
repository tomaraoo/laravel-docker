<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('registers docker scripts in composer.json and appends .env.local to .gitignore when running docker:install', function () {
    $sandbox = sys_get_temp_dir().'/laravel-docker-cmd-'.uniqid('', true);
    File::makeDirectory($sandbox, 0755, true);
    File::put($sandbox.'/composer.json', json_encode([
        'name' => 'acme/sample-laravel-app',
        'scripts' => [
            'test' => '@php artisan test',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    File::put($sandbox.'/.gitignore', "/vendor\n.env\n");

    $originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);

    try {
        $this->artisan('docker:install')
            ->assertSuccessful();
    } finally {
        app()->setBasePath($originalBasePath);
    }

    $composerData = json_decode((string) File::get($sandbox.'/composer.json'), true);
    $gitignore = (string) File::get($sandbox.'/.gitignore');

    File::deleteDirectory($sandbox);

    expect($composerData['scripts'])
        ->toHaveKeys([
            'test',
            'docker:build',
            'docker:up',
            'docker:run',
            'docker:stop',
            'docker:ps',
            'docker:down',
            'docker:down-v',
            'docker:logs',
            'docker:restart',
            'docker:artisan',
            'docker:open',
        ]);

    expect($gitignore)->toContain('.env.local');
});

it('preserves customized docker scripts unless --force is passed', function () {
    $sandbox = sys_get_temp_dir().'/laravel-docker-force-'.uniqid('', true);
    File::makeDirectory($sandbox, 0755, true);
    File::put($sandbox.'/composer.json', json_encode([
        'name' => 'acme/custom-app',
        'scripts' => [
            'docker:ps' => 'custom-ps-command',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);

    try {
        $this->artisan('docker:install')->assertSuccessful();
        $preserved = json_decode((string) File::get($sandbox.'/composer.json'), true);

        $this->artisan('docker:install', ['--force' => true])->assertSuccessful();
        $overwritten = json_decode((string) File::get($sandbox.'/composer.json'), true);
    } finally {
        app()->setBasePath($originalBasePath);
    }

    File::deleteDirectory($sandbox);

    expect($preserved['scripts']['docker:ps'])->toBe('custom-ps-command');
    expect($overwritten['scripts']['docker:ps'])->toBe('@php vendor/bin/laravel-docker ps');
});

it('creates .env.local with default port from .env.example when --init-env is passed', function () {
    $sandbox = sys_get_temp_dir().'/laravel-docker-env-'.uniqid('', true);
    File::makeDirectory($sandbox, 0755, true);
    File::put($sandbox.'/composer.json', json_encode(['name' => 'acme/env-app'])."\n");
    File::put($sandbox.'/.env.example', "APP_NAME=Demo\nDB_DATABASE=demo_db\n");

    $originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);

    try {
        $this->artisan('docker:install', ['--init-env' => true])
            ->assertSuccessful();
    } finally {
        app()->setBasePath($originalBasePath);
    }

    $envLocalExists = is_file($sandbox.'/.env.local');
    $envLocalContents = $envLocalExists ? (string) File::get($sandbox.'/.env.local') : '';

    File::deleteDirectory($sandbox);

    expect($envLocalExists)->toBeTrue();
    expect($envLocalContents)
        ->toContain('DB_DATABASE=demo_db')
        ->toContain('DOCKER_APP_PORT=8080');
});

it('ships Laravel-patched Dockerfile, vhost, and compose configs with no Yii2 remnants', function () {
    $dockerDir = dirname(__DIR__, 3).'/docker';

    $dockerfile = (string) file_get_contents($dockerDir.'/Dockerfile');
    $vhost = (string) file_get_contents($dockerDir.'/vhost.conf');
    $devCompose = (string) file_get_contents($dockerDir.'/docker-compose.yml');
    $prodCompose = (string) file_get_contents($dockerDir.'/docker-compose.prod.yml');

    expect($dockerfile)
        ->toContain('FROM php:8.5-apache')
        ->toContain('pdo_mysql')
        ->toContain('a2enmod rewrite headers')
        ->not->toContain('yiisoftware/yii2-php')
        ->not->toContain('docker-php-ext-install opcache');

    expect($vhost)
        ->toContain('DocumentRoot /app/public')
        ->toContain('<Directory /app/public/storage>')
        ->toContain('<Directory /app/storage>')
        ->toContain('LimitRequestBody 67108864')
        ->not->toContain('/app/frontend/web')
        ->not->toContain('subaybayan');

    expect($devCompose)
        ->toContain('${DOCKER_DB_IMAGE:-mysql:8.0}')
        ->toContain('MYSQL_ROOT_HOST: "%"')
        ->toContain('${APP_CODE_PATH:-.}:/app');

    expect($prodCompose)
        ->toContain('${APP_CODE_PATH:-.}:/app${DOCKER_VOLUME_SELINUX_SUFFIX:-:z}')
        ->not->toContain('mysql:');
});
