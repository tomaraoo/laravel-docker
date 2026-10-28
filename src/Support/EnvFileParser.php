<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Support;

final readonly class EnvFileParser
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
            $line = mb_trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = mb_trim(substr($line, 7));
            }

            $equalsPosition = strpos($line, '=');

            if ($equalsPosition === false) {
                continue;
            }

            $key = mb_trim(substr($line, 0, $equalsPosition));

            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $rawValue = mb_trim(substr($line, $equalsPosition + 1));
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

        if (str_starts_with($rawValue, "'") && str_ends_with($rawValue, "'") && strlen($rawValue) >= 2) {
            return substr($rawValue, 1, -1);
        }

        if (str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"') && strlen($rawValue) >= 2) {
            return substr($rawValue, 1, -1)
                |> (static fn (string $value): string => str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    $value
                ))
                |> (fn (string $value): string => $this->interpolate($value, $resolvedVariables));
        }

        $commentPosition = strpos($rawValue, ' #');

        if ($commentPosition !== false) {
            $rawValue = mb_rtrim(substr($rawValue, 0, $commentPosition));
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
