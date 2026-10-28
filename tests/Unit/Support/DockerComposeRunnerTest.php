<?php

declare(strict_types=1);

use Rft\LaravelDocker\Support\DockerComposeRunner;

it('builds detached up, volume-cleaning down-v, build, stop, ps, and prod compose commands', function () {
    $projectDir = sys_get_temp_dir().'/runner-app-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env.local', "COMPOSE_PROJECT_NAME=demo\nDOCKER_APP_PORT=8081\n");
    file_put_contents($projectDir.'/.env', "COMPOSE_PROJECT_NAME=demo\nAPP_ENV=production\n");

    $runner = new DockerComposeRunner;

    $upPlan = $runner->plan($projectDir, '/pkg/docker', ['laravel-docker', 'up']);
    $downVolumesPlan = $runner->plan($projectDir, '/pkg/docker', ['laravel-docker', 'down-v']);
    $prodPsPlan = $runner->plan($projectDir, '/pkg/docker', ['laravel-docker', 'ps', '--prod']);

    unlink($projectDir.'/.env.local');
    unlink($projectDir.'/.env');
    rmdir($projectDir);

    expect($upPlan['command'])->toBe([
        'docker',
        'compose',
        '--project-name',
        'demo',
        '--env-file',
        $projectDir.'/.env.local',
        '-f',
        '/pkg/docker/docker-compose.yml',
        'up',
        '-d',
    ]);

    expect($downVolumesPlan['command'])->toBe([
        'docker',
        'compose',
        '--project-name',
        'demo',
        '--env-file',
        $projectDir.'/.env.local',
        '-f',
        '/pkg/docker/docker-compose.yml',
        'down',
        '-v',
    ]);

    expect($prodPsPlan['command'])->toBe([
        'docker',
        'compose',
        '--project-name',
        'demo',
        '--env-file',
        $projectDir.'/.env',
        '-f',
        '/pkg/docker/docker-compose.prod.yml',
        'ps',
    ]);
});

it('switches run between foreground stack and one-off service container based on arguments', function () {
    $projectDir = sys_get_temp_dir().'/runner-mode-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env.local', "COMPOSE_PROJECT_NAME=demo\n");

    $runner = new DockerComposeRunner;
    $foregroundPlan = $runner->plan($projectDir, '/pkg/docker', ['laravel-docker', 'run']);
    $serviceRunPlan = $runner->plan($projectDir, '/pkg/docker', ['laravel-docker', 'run', 'app', 'php', 'artisan', 'migrate']);

    unlink($projectDir.'/.env.local');
    rmdir($projectDir);

    expect(array_last($foregroundPlan['command']))->toBe('up');
    expect(array_slice($serviceRunPlan['command'], -6))->toBe(['run', '--rm', 'app', 'php', 'artisan', 'migrate']);
});

it('executes the resolved compose command through the process executor with merged environment', function () {
    $projectDir = sys_get_temp_dir().'/runner-exec-'.uniqid('', true);
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.env.local', "COMPOSE_PROJECT_NAME=execdemo\nDB_DATABASE=exec_db\n");

    $captured = null;
    $runner = new DockerComposeRunner(
        processExecutor: static function (array $command, array $env, string $cwd) use (&$captured): int {
            $captured = ['command' => $command, 'env' => $env, 'cwd' => $cwd];

            return 0;
        }
    );

    $exitCode = $runner->run($projectDir, '/pkg/docker', ['laravel-docker', 'build', '--no-cache']);

    unlink($projectDir.'/.env.local');
    rmdir($projectDir);

    expect($exitCode)->toBe(0);
    expect($captured)->not->toBeNull();
    expect($captured['cwd'])->toBe($projectDir);
    expect(array_slice($captured['command'], -2))->toBe(['build', '--no-cache']);
    expect($captured['env']['DOCKER_MYSQL_DATABASE'])->toBe('exec_db');
});
