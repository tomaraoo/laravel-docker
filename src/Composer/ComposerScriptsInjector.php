<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Composer;

use NoDiscard;

final readonly class ComposerScriptsInjector
{
    public const string PACKAGE_NAME = 'rft/laravel-docker';

    /**
     * Return the canonical Composer scripts registered by this package.
     *
     * @return array<string, list<string>|string>
     */
    #[NoDiscard]
    public function defaultScripts(): array
    {
        return [
            'docker:build' => [
                'Composer\\Config::disableProcessTimeout',
                '@php vendor/bin/laravel-docker build',
            ],
            'docker:up' => [
                'Composer\\Config::disableProcessTimeout',
                '@php vendor/bin/laravel-docker up',
            ],
            'docker:run' => [
                'Composer\\Config::disableProcessTimeout',
                '@php vendor/bin/laravel-docker run',
            ],
            'docker:stop' => '@php vendor/bin/laravel-docker stop',
            'docker:ps' => '@php vendor/bin/laravel-docker ps',
            'docker:down' => '@php vendor/bin/laravel-docker down',
            'docker:down-v' => '@php vendor/bin/laravel-docker down-v',
            'docker:logs' => [
                'Composer\\Config::disableProcessTimeout',
                '@php vendor/bin/laravel-docker logs',
            ],
            'docker:restart' => '@php vendor/bin/laravel-docker restart',
            'docker:artisan' => [
                'Composer\\Config::disableProcessTimeout',
                '@php vendor/bin/laravel-docker artisan',
            ],
            'docker:open' => '@php vendor/bin/laravel-docker open',
        ];
    }

    /**
     * Inject `docker:*` scripts into a consuming Laravel project's composer.json.
     *
     * @return array{updated: bool, added: list<string>}
     */
    public function inject(string $composerJsonPath, bool $overwrite = false): array
    {
        if (! is_file($composerJsonPath) || ! is_readable($composerJsonPath) || ! is_writable($composerJsonPath)) {
            return ['updated' => false, 'added' => []];
        }

        $rawJson = file_get_contents($composerJsonPath);

        if ($rawJson === false || ! json_validate($rawJson)) {
            return ['updated' => false, 'added' => []];
        }

        $decoded = json_decode($rawJson, true);

        if (! is_array($decoded) || ($decoded['name'] ?? null) === self::PACKAGE_NAME) {
            return ['updated' => false, 'added' => []];
        }

        if (! isset($decoded['scripts']) || ! is_array($decoded['scripts'])) {
            $decoded['scripts'] = [];
        }

        $added = [];

        foreach ($this->defaultScripts() as $scriptName => $scriptDefinition) {
            $exists = array_key_exists($scriptName, $decoded['scripts']);

            if (! $exists || ($overwrite && $decoded['scripts'][$scriptName] !== $scriptDefinition)) {
                $decoded['scripts'][$scriptName] = $scriptDefinition;
                $added[] = $scriptName;
            }
        }

        if ($added === []) {
            return ['updated' => false, 'added' => []];
        }

        $indent = $this->detectIndentation($rawJson);
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['updated' => false, 'added' => []];
        }

        if ($indent !== '    ') {
            $encoded = (string) preg_replace_callback(
                '/^( +)/m',
                static fn (array $matches): string => str_repeat($indent, intdiv(strlen($matches[1]), 4)),
                $encoded
            );
        }

        file_put_contents($composerJsonPath, $encoded.PHP_EOL);

        return [
            'updated' => true,
            'added' => $added,
        ];
    }

    /**
     * Ensure `.env.local` is ignored in the target project's `.gitignore` so dev credentials stay untracked.
     */
    public function ensureEnvLocalGitignored(string $gitignorePath): bool
    {
        if (! is_file($gitignorePath) || ! is_readable($gitignorePath) || ! is_writable($gitignorePath)) {
            return false;
        }

        $contents = file_get_contents($gitignorePath);

        if ($contents === false) {
            return false;
        }

        $lines = array_map(mb_trim(...), preg_split('/\R/', $contents) ?: []);
        $alreadyIgnored = array_any(
            $lines,
            static fn (string $line): bool => in_array($line, ['.env.local', '/.env.local', '.env.*', '/.env.*', '.env*'], true)
        );

        if ($alreadyIgnored) {
            return false;
        }

        $separator = ($contents !== '' && ! str_ends_with($contents, "\n")) ? PHP_EOL : '';
        file_put_contents($gitignorePath, $contents.$separator.'.env.local'.PHP_EOL);

        return true;
    }

    private function detectIndentation(string $rawJson): string
    {
        if (preg_match('/^\R?(\t+| +)"/m', $rawJson, $matches) === 1) {
            return $matches[1];
        }

        return '    ';
    }
}
