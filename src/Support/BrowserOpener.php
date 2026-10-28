<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Support;

use Closure;
use InvalidArgumentException;

final class BrowserOpener
{
    /**
     * @param  (Closure(string): bool)|null  $binaryExistsResolver
     */
    public function __construct(
        private readonly string $osFamily = PHP_OS_FAMILY,
        private readonly ?bool $isWslOverride = null,
        private readonly ?Closure $binaryExistsResolver = null,
    ) {}

    /**
     * Build the OS-specific command to open the given HTTP(S) URL in the default browser.
     *
     * @return list<string>
     */
    public function buildCommand(string $url): array
    {
        $trimmedUrl = trim($url);

        if (preg_match('#^https?://[^\s]+$#i', $trimmedUrl) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid browser URL [%s].', $url));
        }

        return match ($this->osFamily) {
            'Darwin' => ['open', $trimmedUrl],
            'Windows' => ['cmd', '/c', 'start', '', $trimmedUrl],
            default => $this->buildLinuxOrWslCommand($trimmedUrl),
        };
    }

    /**
     * Open the given URL in the host browser (or return the command in dry-run mode).
     *
     * @return array{url: string, command: list<string>, exit_code: int}
     */
    public function open(string $url, bool $dryRun = false): array
    {
        $command = $this->buildCommand($url);

        if ($dryRun) {
            return [
                'url' => $url,
                'command' => $command,
                'exit_code' => 0,
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            return [
                'url' => $url,
                'command' => $command,
                'exit_code' => 1,
            ];
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);

        return [
            'url' => $url,
            'command' => $command,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildLinuxOrWslCommand(string $url): array
    {
        if ($this->isWsl()) {
            if ($this->binaryExists('wslview')) {
                return ['wslview', $url];
            }

            if ($this->binaryExists('cmd.exe')) {
                return ['cmd.exe', '/c', 'start', '', $url];
            }

            if ($this->binaryExists('explorer.exe')) {
                return ['explorer.exe', $url];
            }
        }

        if ($this->binaryExists('xdg-open')) {
            return ['xdg-open', $url];
        }

        if ($this->binaryExists('gio')) {
            return ['gio', 'open', $url];
        }

        if ($this->binaryExists('sensible-browser')) {
            return ['sensible-browser', $url];
        }

        return ['xdg-open', $url];
    }

    private function isWsl(): bool
    {
        if ($this->isWslOverride !== null) {
            return $this->isWslOverride;
        }

        if (getenv('WSL_DISTRO_NAME') !== false) {
            return true;
        }

        if (is_readable('/proc/version')) {
            $version = (string) file_get_contents('/proc/version');

            return stripos($version, 'microsoft') !== false || stripos($version, 'wsl') !== false;
        }

        return false;
    }

    private function binaryExists(string $binary): bool
    {
        if ($this->binaryExistsResolver !== null) {
            return ($this->binaryExistsResolver)($binary);
        }

        $pathEnv = getenv('PATH');

        if ($pathEnv === false || $pathEnv === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
