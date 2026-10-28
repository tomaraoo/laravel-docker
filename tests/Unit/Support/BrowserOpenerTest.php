<?php

declare(strict_types=1);

use Rft\LaravelDocker\Support\BrowserOpener;

it('builds platform-appropriate browser launch commands across macOS, Windows, WSL, and Linux', function () {
    expect(new BrowserOpener('Darwin', false)->buildCommand('http://localhost:8080'))
        ->toBe(['open', 'http://localhost:8080']);

    expect(new BrowserOpener('Windows', false)->buildCommand('http://localhost:8080'))
        ->toBe(['cmd', '/c', 'start', '', 'http://localhost:8080']);

    expect(new BrowserOpener('Linux', true, static fn (string $bin): bool => $bin === 'wslview')->buildCommand('http://localhost:8080'))
        ->toBe(['wslview', 'http://localhost:8080']);

    expect(new BrowserOpener('Linux', false, static fn (string $bin): bool => $bin === 'xdg-open')->buildCommand('http://localhost:8080'))
        ->toBe(['xdg-open', 'http://localhost:8080']);
});

it('rejects non-http URLs before spawning a browser command', function () {
    expect(static fn (): array => new BrowserOpener('Linux', false)->buildCommand('file:///etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});
