<?php

declare(strict_types=1);

use Rft\LaravelDocker\Composer\ComposerScriptsInjector;

it('injects all docker scripts while preserving two-space json indentation', function () {
    $tempFile = sys_get_temp_dir().'/composer-indent-'.uniqid('', true).'.json';
    file_put_contents($tempFile, "{\n  \"name\": \"acme/two-space-app\",\n  \"scripts\": {\n    \"lint\": \"pint\"\n  }\n}\n");

    $injector = new ComposerScriptsInjector;
    $result = $injector->inject($tempFile);
    $written = (string) file_get_contents($tempFile);
    unlink($tempFile);

    expect($result['updated'])->toBeTrue();
    expect($result['added'])->toContain('docker:build', 'docker:up', 'docker:run', 'docker:down-v', 'docker:open');
    expect($written)->toContain("\n  \"scripts\": {\n    \"lint\": \"pint\",\n    \"docker:build\": [");
});

it('skips injecting scripts into the rft/laravel-docker package itself', function () {
    $tempFile = sys_get_temp_dir().'/composer-self-'.uniqid('', true).'.json';
    file_put_contents($tempFile, json_encode(['name' => 'rft/laravel-docker'], JSON_PRETTY_PRINT));

    $injector = new ComposerScriptsInjector;
    $result = $injector->inject($tempFile);
    unlink($tempFile);

    expect($result)->toBe(['updated' => false, 'added' => []]);
});

it('does not duplicate .env.local in .gitignore when already ignored', function () {
    $tempFile = sys_get_temp_dir().'/gitignore-'.uniqid('', true);
    file_put_contents($tempFile, "/vendor\n.env\n");

    $injector = new ComposerScriptsInjector;
    $firstRun = $injector->ensureEnvLocalGitignored($tempFile);
    $secondRun = $injector->ensureEnvLocalGitignored($tempFile);
    $contents = (string) file_get_contents($tempFile);
    unlink($tempFile);

    expect($firstRun)->toBeTrue();
    expect($secondRun)->toBeFalse();
    expect(substr_count($contents, '.env.local'))->toBe(1);
});
