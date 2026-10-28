<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Enums;

enum DockerEnvironment: string
{
    case Local = 'local';
    case Staging = 'staging';
    case Production = 'production';

    public function composeFilename(): string
    {
        return match ($this) {
            self::Local => 'docker-compose.yml',
            self::Staging => 'docker-compose.staging.yml',
            self::Production => 'docker-compose.prod.yml',
        };
    }

    public function preferredEnvFilename(): string
    {
        return match ($this) {
            self::Local => '.env.local',
            self::Staging, self::Production => '.env',
        };
    }

    public function defaultAppPort(): string
    {
        return match ($this) {
            self::Local => '8080',
            self::Staging, self::Production => '80',
        };
    }

    public static function fromInput(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'local', 'dev', 'development' => self::Local,
            'staging', 'stage' => self::Staging,
            'prod', 'production' => self::Production,
            default => null,
        };
    }
}
