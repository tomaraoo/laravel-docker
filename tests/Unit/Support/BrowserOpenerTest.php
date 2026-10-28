<?php

declare(strict_types=1);

use Rft\LaravelDocker\Support\BrowserOpener;

it('builds platform-appropriate browser launch commands across macOS, Windows, WSL, and Linux', function () {
    $macOpener = new BrowserOpener('Darwin', false);
    $winOpener = new BrowserOpener('Windows', false);
    $wslOpener = new BrowserOpener('Linux', true, static fn (string $bin): bool => $bin === 'wslview');
    $linuxOpener = new BrowserOpener('Linux', false, static fn (string $bin): bool => $bin === 'xdg-open');

    expect($macOpener->buildCommand('http://localhost:8080'))->toBe(['open', 'http://localhost:8080']);
    expect($winOpener->buildCommand('http://localhost:8080'))->toBe(['cmd', '/c', 'start', '', 'http://localhost:8080']);
    expect($wslOpener->buildCommand('http://localhost:8080'))->toBe(['wslview', 'http://localhost:8080']);
    expect($linuxOpener->buildCommand('http://localhost:8080'))->toBe(['xdg-open', 'http://localhost:8080']);
});

it('rejects non-http URLs before spawning a browser command', function () {
    $opener = new BrowserOpener('Linux', false);

    expect(fn (): array => $opener->buildCommand('file:///etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});
