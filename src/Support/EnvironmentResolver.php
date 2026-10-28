<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Support;

use Rft\LaravelDocker\Enums\DockerEnvironment;

final readonly class EnvironmentResolver
{
    public function __construct(
        private EnvFileParser $parser = new EnvFileParser,
    ) {}

    /**
     * Resolve the Docker execution context, env file, compose file, and normalized variables.
     *
     * @return array{
     *     environment: DockerEnvironment,
     *     env_file: ?string,
     *     using_fallback_env: bool,
     *     compose_file: string,
     *     project_name: string,
     *     open_url: string,
     *     compose_env: array<string, string>
     * }
     */
    public function resolve(
        string $projectRoot,
        string $dockerDirectory,
        ?DockerEnvironment $explicitEnvironment = null,
        ?int $detectedUid = null,
        ?int $detectedGid = null,
    ): array {
        $projectRoot = rtrim($projectRoot, '/\\');
        $dockerDirectory = rtrim($dockerDirectory, '/\\');

        $envLocalPath = $projectRoot.'/.env.local';
        $envDefaultPath = $projectRoot.'/.env';

        $hasEnvLocal = is_file($envLocalPath);
        $hasEnvDefault = is_file($envDefaultPath);

        $defaultVars = $hasEnvDefault ? $this->parser->parseFile($envDefaultPath) : [];
        $localVars = $hasEnvLocal ? $this->parser->parseFile($envLocalPath) : [];

        $environment = $explicitEnvironment ?? $this->detectEnvironment($hasEnvLocal, $defaultVars);

        $envFile = null;
        $usingFallbackEnv = false;
        $activeVars = [];

        if ($environment === DockerEnvironment::Local) {
            if ($hasEnvLocal) {
                $envFile = $envLocalPath;
                $activeVars = $localVars;
            } elseif ($hasEnvDefault) {
                $envFile = $envDefaultPath;
                $activeVars = $defaultVars;
                $usingFallbackEnv = true;
            }
        } elseif ($hasEnvDefault) {
            $envFile = $envDefaultPath;
            $activeVars = $defaultVars;
        }

        $projectName = $this->resolveProjectName($projectRoot, $activeVars);
        $appPort = $this->nonEmpty($activeVars['DOCKER_APP_PORT'] ?? null) ?? $environment->defaultAppPort();
        $openUrl = $this->resolveOpenUrl($activeVars, $appPort);

        $composeEnv = $this->buildComposeEnvironment(
            environment: $environment,
            projectRoot: $projectRoot,
            dockerDirectory: $dockerDirectory,
            projectName: $projectName,
            appPort: $appPort,
            activeVars: $activeVars,
            detectedUid: $detectedUid,
            detectedGid: $detectedGid,
        );

        return [
            'environment' => $environment,
            'env_file' => $envFile,
            'using_fallback_env' => $usingFallbackEnv,
            'compose_file' => $dockerDirectory.'/'.$environment->composeFilename(),
            'project_name' => $projectName,
            'open_url' => $openUrl,
            'compose_env' => $composeEnv,
        ];
    }

    /**
     * @param  array<string, string>  $defaultVars
     */
    private function detectEnvironment(bool $hasEnvLocal, array $defaultVars): DockerEnvironment
    {
        $configuredAppEnv = DockerEnvironment::fromInput($defaultVars['APP_ENV'] ?? null);

        if (! $hasEnvLocal && $configuredAppEnv !== null) {
            return $configuredAppEnv;
        }

        return DockerEnvironment::Local;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function resolveProjectName(string $projectRoot, array $vars): string
    {
        $explicitName = $this->nonEmpty($vars['COMPOSE_PROJECT_NAME'] ?? null);

        if ($explicitName !== null) {
            return $this->slugifyComposeName($explicitName);
        }

        $appName = $this->nonEmpty($vars['APP_NAME'] ?? null);

        if ($appName !== null && mb_strtolower($appName) !== 'laravel') {
            return $this->slugifyComposeName($appName);
        }

        return $this->slugifyComposeName(basename($projectRoot));
    }

    private function slugifyComposeName(string $rawName): string
    {
        $slug = $rawName
            |> mb_trim(...)
            |> mb_strtolower(...)
            |> (static fn (string $value): string => (string) preg_replace('/[^a-z0-9]+/', '-', $value))
            |> (static fn (string $value): string => trim($value, '-'));

        if ($slug === '' || preg_match('/^[a-z0-9]/', $slug) !== 1) {
            return 'laravel-app';
        }

        return $slug;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function resolveOpenUrl(array $vars, string $appPort): string
    {
        $explicitOpenUrl = $this->nonEmpty($vars['DOCKER_OPEN_URL'] ?? null);

        if ($explicitOpenUrl !== null) {
            return $explicitOpenUrl;
        }

        $host = 'localhost';
        $port = $appPort;

        if (str_contains($appPort, ':')) {
            $segments = explode(':', $appPort);
            $port = (string) array_last($segments);
            $candidateHost = implode(':', array_slice($segments, 0, -1));

            if ($candidateHost !== '' && $candidateHost !== '0.0.0.0' && $candidateHost !== '[::]') {
                $host = $candidateHost;
            }
        }

        if ($port === '80' || $port === '') {
            return sprintf('http://%s', $host);
        }

        if ($port === '443') {
            return sprintf('https://%s', $host);
        }

        return sprintf('http://%s:%s', $host, $port);
    }

    /**
     * @param  array<string, string>  $activeVars
     * @return array<string, string>
     */
    private function buildComposeEnvironment(
        DockerEnvironment $environment,
        string $projectRoot,
        string $dockerDirectory,
        string $projectName,
        string $appPort,
        array $activeVars,
        ?int $detectedUid,
        ?int $detectedGid,
    ): array {
        [$defaultUid, $defaultGid, $defaultExtraGid] = $this->resolveDefaultOwnership(
            $environment,
            $detectedUid,
            $detectedGid,
        );

        $hostUid = $this->nonEmpty($activeVars['HOST_UID'] ?? null) ?? $defaultUid;
        $hostGid = $this->nonEmpty($activeVars['HOST_GID'] ?? null) ?? $defaultGid;
        $hostExtraGid = $this->nonEmpty($activeVars['HOST_EXTRA_GID'] ?? null) ?? $defaultExtraGid;
        $codePath = $this->nonEmpty($activeVars['APP_CODE_PATH'] ?? null) ?? $projectRoot;

        $composeEnv = array_merge($activeVars, [
            'COMPOSE_PROJECT_NAME' => $projectName,
            'LARAVEL_DOCKER_CONTEXT' => $dockerDirectory,
            'APP_CODE_PATH' => $codePath,
            'DOCKER_APP_PORT' => $appPort,
            'HOST_UID' => $hostUid,
            'HOST_GID' => $hostGid,
            'HOST_EXTRA_GID' => $hostExtraGid,
        ]);

        if ($environment === DockerEnvironment::Local) {
            $dbConnection = mb_strtolower($activeVars['DB_CONNECTION'] ?? 'mysql');
            $rawDbName = $this->nonEmpty($activeVars['DB_DATABASE'] ?? null);

            if ($dbConnection === 'sqlite' || $rawDbName === null || str_contains($rawDbName, '/') || str_ends_with($rawDbName, '.sqlite')) {
                $mysqlDatabase = str_replace('-', '_', $projectName);
            } else {
                $mysqlDatabase = $rawDbName;
            }

            $rawDbUser = $this->nonEmpty($activeVars['DB_USERNAME'] ?? null) ?? 'laravel';
            $rawDbPassword = $this->nonEmpty($activeVars['DB_PASSWORD'] ?? null) ?? 'secret';

            $mysqlUser = mb_strtolower($rawDbUser) === 'root' ? 'laravel' : $rawDbUser;
            $mysqlPassword = $rawDbPassword;
            $mysqlRootPassword = $this->nonEmpty($activeVars['DB_ROOT_PASSWORD'] ?? null) ?? $rawDbPassword;

            $configuredDbPort = $this->nonEmpty($activeVars['DB_PORT'] ?? null);
            $forwardDbPort = $this->nonEmpty($activeVars['FORWARD_DB_PORT'] ?? null)
                ?? (($configuredDbPort !== null && $configuredDbPort !== '3306') ? $configuredDbPort : '33060');

            $composeEnv['DOCKER_LARAVEL_ENV'] = 'local';
            $composeEnv['DOCKER_DB_IMAGE'] = $this->nonEmpty($activeVars['DOCKER_DB_IMAGE'] ?? null) ?? 'mysql:8.0';
            $composeEnv['DOCKER_DB_HOST'] = $this->nonEmpty($activeVars['DOCKER_DB_HOST'] ?? null) ?? 'db';
            $composeEnv['DOCKER_INTERNAL_DB_PORT'] = $this->nonEmpty($activeVars['DOCKER_INTERNAL_DB_PORT'] ?? null) ?? '3306';
            $composeEnv['DOCKER_HOST_DB_PORT'] = $forwardDbPort;
            $composeEnv['DOCKER_MYSQL_DATABASE'] = $mysqlDatabase;
            $composeEnv['DOCKER_MYSQL_USER'] = $mysqlUser;
            $composeEnv['DOCKER_MYSQL_PASSWORD'] = $mysqlPassword;
            $composeEnv['DOCKER_MYSQL_ROOT_PASSWORD'] = $mysqlRootPassword;
        }

        return $composeEnv;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveDefaultOwnership(
        DockerEnvironment $environment,
        ?int $detectedUid,
        ?int $detectedGid,
    ): array {
        if ($environment !== DockerEnvironment::Local) {
            return ['48', '48', '48'];
        }

        $uid = $detectedUid ?? (function_exists('posix_getuid') ? posix_getuid() : 1000);
        $gid = $detectedGid ?? (function_exists('posix_getgid') ? posix_getgid() : 1000);

        if ($uid === 0) {
            $uid = 1000;
        }

        if ($gid === 0) {
            $gid = 1000;
        }

        return [(string) $uid, (string) $gid, '33'];
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
