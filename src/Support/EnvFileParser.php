<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Support;

final class EnvFileParser
{
    /**
     * Parse a dotenv-formatted file into key-value string pairs.
     *
     * @return array<string, string>
     */
    public function parseFile(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return [];
        }

        return $this->parseString($contents);
    }

    /**
     * Parse dotenv content string into key-value string pairs.
     *
     * @return array<string, string>
     */
    public function parseString(string $contents): array
    {
        $variables = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $equalsPosition = strpos($line, '=');

            if ($equalsPosition === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equalsPosition));

            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $rawValue = trim(substr($line, $equalsPosition + 1));
            $variables[$key] = $this->normalizeValue($rawValue, $variables);
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $resolvedVariables
     */
    private function normalizeValue(string $rawValue, array $resolvedVariables): string
    {
        if ($rawValue === '') {
            return '';
        }

        $firstChar = $rawValue[0];
        $lastChar = $rawValue[strlen($rawValue) - 1];

        if ($firstChar === "'" && $lastChar === "'" && strlen($rawValue) >= 2) {
            return substr($rawValue, 1, -1);
        }

        if ($firstChar === '"' && $lastChar === '"' && strlen($rawValue) >= 2) {
            $unquoted = substr($rawValue, 1, -1);
            $unquoted = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $unquoted);

            return $this->interpolate($unquoted, $resolvedVariables);
        }

        $commentPosition = strpos($rawValue, ' #');

        if ($commentPosition !== false) {
            $rawValue = rtrim(substr($rawValue, 0, $commentPosition));
        }

        return $this->interpolate($rawValue, $resolvedVariables);
    }

    /**
     * @param  array<string, string>  $resolvedVariables
     */
    private function interpolate(string $value, array $resolvedVariables): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $matches): string => $resolvedVariables[$matches[1]] ?? '',
            $value
        );
    }
}
