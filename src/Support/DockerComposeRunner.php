<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Support;

use Closure;
use InvalidArgumentException;
use Rft\LaravelDocker\Enums\DockerEnvironment;

final class DockerComposeRunner
{
    /**
     * @param  (Closure(list<string>, array<string, string>, string): int)|null  $processExecutor
     */
    public function __construct(
        private readonly EnvironmentResolver $environmentResolver = new EnvironmentResolver,
        private readonly BrowserOpener $browserOpener = new BrowserOpener,
        private readonly ?Closure $processExecutor = null,
    ) {}

    /**
     * Parse CLI arguments into normalized action, environment override, dry-run flag, and extra args.
     *
     * @param  list<string>  $argv
     * @return array{
     *     action: string,
     *     environment: ?DockerEnvironment,
     *     dry_run: bool,
     *     extra_args: list<string>
     * }
     */
    public function parseArguments(array $argv): array
    {
        $args = $argv;
        array_shift($args);

        $action = 'help';
        $explicitEnvironment = null;
        $dryRun = false;
        $extraArgs = [];
        $actionResolved = false;

        foreach ($args as $arg) {
            if ($arg === '--') {
                continue;
            }

            if ($arg === '--dry-run') {
                $dryRun = true;

                continue;
            }

            if ($arg === '--local' || $arg === '--dev') {
                $explicitEnvironment = DockerEnvironment::Local;

                continue;
            }

            if ($arg === '--staging') {
                $explicitEnvironment = DockerEnvironment::Staging;

                continue;
            }

            if ($arg === '--prod' || $arg === '--production') {
                $explicitEnvironment = DockerEnvironment::Production;

                continue;
            }

            if (str_starts_with($arg, '--env=')) {
                $parsed = DockerEnvironment::fromInput(substr($arg, 6));

                if ($parsed === null) {
                    throw new InvalidArgumentException(sprintf('Unsupported environment [%s]. Expected local, staging, or prod.', substr($arg, 6)));
                }

                $explicitEnvironment = $parsed;

                continue;
            }

            if (! $actionResolved) {
                $action = mb_strtolower($arg);
                $actionResolved = true;

                continue;
            }

            $extraArgs[] = $arg;
        }

        return [
            'action' => $action,
            'environment' => $explicitEnvironment,
            'dry_run' => $dryRun,
            'extra_args' => $extraArgs,
        ];
    }

    /**
     * Build the full execution plan for a given CLI invocation without running side effects.
     *
     * @param  list<string>  $argv
     * @return array{
     *     action: string,
     *     dry_run: bool,
     *     environment: DockerEnvironment,
     *     env_file: ?string,
     *     using_fallback_env: bool,
     *     compose_file: string,
     *     project_name: string,
     *     open_url: string,
     *     command: list<string>,
     *     compose_env: array<string, string>
     * }
     */
    public function plan(string $projectRoot, string $dockerDirectory, array $argv): array
    {
        $parsed = $this->parseArguments($argv);
        $resolved = $this->environmentResolver->resolve(
            projectRoot: $projectRoot,
            dockerDirectory: $dockerDirectory,
            explicitEnvironment: $parsed['environment'],
        );

        $action = $parsed['action'];

        if ($action === 'open' || $action === 'browse') {
            $command = $this->browserOpener->buildCommand($resolved['open_url']);
        } elseif ($action === 'help' || $action === '--help' || $action === '-h') {
            $command = [];
        } else {
            $subcommand = $this->resolveComposeSubcommand($action, $parsed['extra_args']);
            $command = [
                'docker',
                'compose',
                '--project-name',
                $resolved['project_name'],
            ];

            if ($resolved['env_file'] !== null) {
                $command[] = '--env-file';
                $command[] = $resolved['env_file'];
            }

            $command[] = '-f';
            $command[] = $resolved['compose_file'];

            foreach ($subcommand as $part) {
                $command[] = $part;
            }
        }

        return [
            'action' => $action,
            'dry_run' => $parsed['dry_run'],
            'environment' => $resolved['environment'],
            'env_file' => $resolved['env_file'],
            'using_fallback_env' => $resolved['using_fallback_env'],
            'compose_file' => $resolved['compose_file'],
            'project_name' => $resolved['project_name'],
            'open_url' => $resolved['open_url'],
            'command' => $command,
            'compose_env' => $resolved['compose_env'],
        ];
    }

    /**
     * Execute the requested Docker or browser command and return its exit code.
     *
     * @param  list<string>  $argv
     * @param  (Closure(string): void)|null  $outputWriter
     */
    public function run(
        string $projectRoot,
        string $dockerDirectory,
        array $argv,
        ?Closure $outputWriter = null,
    ): int {
        $write = $outputWriter ?? static function (string $line): void {
            fwrite(STDOUT, $line.PHP_EOL);
        };

        $plan = $this->plan($projectRoot, $dockerDirectory, $argv);
        $action = $plan['action'];

        if ($action === 'help' || $action === '--help' || $action === '-h') {
            $write($this->usageText());

            return 0;
        }

        if ($plan['using_fallback_env'] && $plan['env_file'] !== null) {
            $write(sprintf(
                '[laravel-docker] Notice: .env.local not found for local environment; falling back to %s.',
                basename($plan['env_file'])
            ));
        }

        if ($action === 'open' || $action === 'browse') {
            $write(sprintf('[laravel-docker] Opening %s ...', $plan['open_url']));

            if ($plan['dry_run']) {
                $write('[dry-run] '.$this->formatCommand($plan['command']));

                return 0;
            }

            $result = $this->browserOpener->open($plan['open_url'], false);

            if ($result['exit_code'] !== 0) {
                $write(sprintf(
                    '[laravel-docker] Could not launch a GUI browser automatically. Visit: %s',
                    $plan['open_url']
                ));
            }

            return $result['exit_code'];
        }

        if ($plan['dry_run']) {
            $write(sprintf(
                '[laravel-docker] env=%s | env_file=%s | project=%s',
                $plan['environment']->value,
                $plan['env_file'] ?? '(none)',
                $plan['project_name']
            ));
            $write('[dry-run] '.$this->formatCommand($plan['command']));

            return 0;
        }

        if ($this->processExecutor !== null) {
            return ($this->processExecutor)($plan['command'], $plan['compose_env'], $projectRoot);
        }

        return $this->executeInteractiveProcess($plan['command'], $plan['compose_env'], $projectRoot);
    }

    /**
     * @param  list<string>  $extraArgs
     * @return list<string>
     */
    private function resolveComposeSubcommand(string $action, array $extraArgs): array
    {
        return match ($action) {
            'build' => ['build', ...$extraArgs],
            'up' => $this->buildUpArgs($extraArgs),
            'run' => $this->buildRunArgs($extraArgs),
            'stop' => ['stop', ...$extraArgs],
            'ps' => ['ps', ...$extraArgs],
            'down' => ['down', ...$extraArgs],
            'down-v', 'clean' => $this->buildDownVolumesArgs($extraArgs),
            'logs' => $this->buildLogsArgs($extraArgs),
            'restart' => ['restart', ...$extraArgs],
            'exec', 'shell', 'sh' => $extraArgs !== [] ? ['exec', ...$extraArgs] : ['exec', 'app', 'bash'],
            'artisan' => ['exec', 'app', 'php', 'artisan', ...$extraArgs],
            default => throw new InvalidArgumentException(sprintf('Unknown laravel-docker command [%s]. Run with --help to list commands.', $action)),
        };
    }

    /**
     * @param  list<string>  $extraArgs
     * @return list<string>
     */
    private function buildUpArgs(array $extraArgs): array
    {
        if (in_array('-d', $extraArgs, true) || in_array('--detach', $extraArgs, true) || in_array('--abort-on-container-exit', $extraArgs, true)) {
            return ['up', ...$extraArgs];
        }

        return ['up', '-d', ...$extraArgs];
    }

    /**
     * @param  list<string>  $extraArgs
     * @return list<string>
     */
    private function buildRunArgs(array $extraArgs): array
    {
        if ($extraArgs === [] || str_starts_with($extraArgs[0], '-')) {
            return ['up', ...$extraArgs];
        }

        return ['run', '--rm', ...$extraArgs];
    }

    /**
     * @param  list<string>  $extraArgs
     * @return list<string>
     */
    private function buildDownVolumesArgs(array $extraArgs): array
    {
        if (in_array('-v', $extraArgs, true) || in_array('--volumes', $extraArgs, true)) {
            return ['down', ...$extraArgs];
        }

        return ['down', '-v', ...$extraArgs];
    }

    /**
     * @param  list<string>  $extraArgs
     * @return list<string>
     */
    private function buildLogsArgs(array $extraArgs): array
    {
        if (in_array('-f', $extraArgs, true) || in_array('--follow', $extraArgs, true) || in_array('--no-follow', $extraArgs, true)) {
            $filtered = array_values(array_filter($extraArgs, static fn (string $arg): bool => $arg !== '--no-follow'));

            return ['logs', ...$filtered];
        }

        return ['logs', '-f', ...$extraArgs];
    }

    /**
     * @param  list<string>  $command
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => preg_match('/^[A-Za-z0-9_\/.:=-]+$/', $part) === 1 ? $part : escapeshellarg($part),
            $command
        ));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $composeEnv
     */
    private function executeInteractiveProcess(array $command, array $composeEnv, string $workingDirectory): int
    {
        $processEnv = array_merge(getenv(), $composeEnv);

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDirectory, $processEnv);

        if (! is_resource($process)) {
            fwrite(STDERR, '[laravel-docker] Failed to start docker compose process.'.PHP_EOL);

            return 1;
        }

        return proc_close($process);
    }

    private function usageText(): string
    {
        return <<<'TEXT'
Laravel Docker Runtime (rft/laravel-docker)

Usage:
  vendor/bin/laravel-docker <command> [--local|--staging|--prod] [--dry-run] [extra args...]

Commands:
  build      Build the PHP 8.5 + Apache runtime image
  up         Start containers in the background (up -d)
  run        Run stack attached in foreground, or `run --rm <service> <cmd>`
  stop       Stop running containers without removing them
  ps         List container status
  down       Stop and remove containers and network
  down-v     Stop and remove containers, network, and named volumes (down -v)
  logs       Tail container logs (logs -f)
  restart    Restart containers
  exec       Open bash in `app` container (or pass custom service/command)
  artisan    Run `php artisan` inside the `app` container
  open       Open the resolved localhost URL in your default browser
TEXT;
    }
}
