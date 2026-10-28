<?php

declare(strict_types=1);

use Rft\LaravelDocker\Enums\DockerEnvironment;
use Rft\LaravelDocker\Support\EnvironmentResolver;

it('uses .env.local and local compose file when .env.local exists even if .env is production', function () {
    $projectDir = sys_get_temp_dir().'/env-local-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env', "APP_ENV=production\nDB_USERNAME=prod_user\nDOCKER_APP_PORT=80\n");
    file_put_contents($projectDir.'/.env.local', "APP_NAME=\"Portal App\"\nDB_DATABASE=portal_local\nDB_USERNAME=local_user\nDB_PASSWORD=local_pass\nDOCKER_APP_PORT=8088\n");

    $resolver = new EnvironmentResolver;
    $resolved = $resolver->resolve($projectDir, '/pkg/docker', null, 1001, 1002);

    unlink($projectDir.'/.env');
    unlink($projectDir.'/.env.local');
    rmdir($projectDir);

    expect($resolved['environment'])->toBe(DockerEnvironment::Local);
    expect($resolved['env_file'])->toBe($projectDir.'/.env.local');
    expect($resolved['using_fallback_env'])->toBeFalse();
    expect($resolved['compose_file'])->toBe('/pkg/docker/docker-compose.yml');
    expect($resolved['project_name'])->toBe('portal-app');
    expect($resolved['open_url'])->toBe('http://localhost:8088');
    expect($resolved['compose_env']['DOCKER_MYSQL_USER'])->toBe('local_user');
    expect($resolved['compose_env']['DOCKER_MYSQL_PASSWORD'])->toBe('local_pass');
    expect($resolved['compose_env']['HOST_UID'])->toBe('1001');
    expect($resolved['compose_env']['HOST_GID'])->toBe('1002');
});

it('uses .env and production compose file when APP_ENV is production and .env.local is absent', function () {
    $projectDir = sys_get_temp_dir().'/env-prod-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env', "APP_NAME=SubayPortal\nAPP_ENV=production\nAPP_CODE_PATH=/www/portal\n");

    $resolver = new EnvironmentResolver;
    $resolved = $resolver->resolve($projectDir, '/pkg/docker');

    unlink($projectDir.'/.env');
    rmdir($projectDir);

    expect($resolved['environment'])->toBe(DockerEnvironment::Production);
    expect($resolved['env_file'])->toBe($projectDir.'/.env');
    expect($resolved['compose_file'])->toBe('/pkg/docker/docker-compose.prod.yml');
    expect($resolved['project_name'])->toBe('subayportal');
    expect($resolved['open_url'])->toBe('http://localhost');
    expect($resolved['compose_env']['HOST_UID'])->toBe('48');
    expect($resolved['compose_env']['HOST_GID'])->toBe('48');
    expect($resolved['compose_env']['APP_CODE_PATH'])->toBe('/www/portal');
});

it('falls back to .env with fallback flag when running local environment without .env.local', function () {
    $projectDir = sys_get_temp_dir().'/env-fallback-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env', "APP_ENV=local\nDB_DATABASE=fallback_db\n");

    $resolver = new EnvironmentResolver;
    $resolved = $resolver->resolve($projectDir, '/pkg/docker');

    unlink($projectDir.'/.env');
    rmdir($projectDir);

    expect($resolved['environment'])->toBe(DockerEnvironment::Local);
    expect($resolved['env_file'])->toBe($projectDir.'/.env');
    expect($resolved['using_fallback_env'])->toBeTrue();
    expect($resolved['compose_env']['DOCKER_MYSQL_DATABASE'])->toBe('fallback_db');
});

it('remaps root database user and sqlite database path to safe MySQL container defaults', function () {
    $projectDir = sys_get_temp_dir().'/sqlite-app';
    if (! is_dir($projectDir)) {
        mkdir($projectDir, 0755, true);
    }
    file_put_contents($projectDir.'/.env.local', "DB_CONNECTION=sqlite\nDB_DATABASE=/abs/database.sqlite\nDB_USERNAME=root\nDB_PASSWORD=rootpass\n");

    $resolver = new EnvironmentResolver;
    $resolved = $resolver->resolve($projectDir, '/pkg/docker');

    unlink($projectDir.'/.env.local');
    rmdir($projectDir);

    expect($resolved['compose_env']['DOCKER_MYSQL_DATABASE'])->toBe('sqlite_app');
    expect($resolved['compose_env']['DOCKER_MYSQL_USER'])->toBe('laravel');
    expect($resolved['compose_env']['DOCKER_MYSQL_ROOT_PASSWORD'])->toBe('rootpass');
});

it('resolves localhost browser URLs from plain ports, host-bound ports, and explicit overrides', function () {
    $resolver = new EnvironmentResolver;

    expect($resolver->resolveOpenUrl([], '8080'))->toBe('http://localhost:8080');
    expect($resolver->resolveOpenUrl([], '80'))->toBe('http://localhost');
    expect($resolver->resolveOpenUrl([], '127.0.0.1:8085'))->toBe('http://127.0.0.1:8085');
    expect($resolver->resolveOpenUrl([], '0.0.0.0:9000'))->toBe('http://localhost:9000');
    expect($resolver->resolveOpenUrl(['DOCKER_OPEN_URL' => 'http://portal.test:8080'], '8080'))->toBe('http://portal.test:8080');
});
