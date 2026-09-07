<?php

/**
 * Environment loader (source §64, PROJ-005).
 *
 * In Docker, values arrive through compose's `env_file`, so this loader finds nothing
 * to do and that is the normal case. It exists for running CLI scripts and tests
 * directly on a host, where .env has not been injected into the process.
 *
 * Real environment variables always win: this never overwrites a value that is already
 * set, so a deployment cannot be silently overridden by a stray file.
 */

declare(strict_types=1);

if (!function_exists('ioms_load_env')) {
    /**
     * Read KEY=VALUE lines from a .env file into the process environment.
     *
     * @param string $path Absolute path to the file. A missing file is not an error.
     */
    function ioms_load_env(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $entry = ioms_parse_env_line($line);
            if ($entry === null) {
                continue;
            }

            [$key, $value] = $entry;

            // Already provided by the real environment — leave it alone.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    /**
     * Parse one .env line.
     *
     * @return array{0: string, 1: string}|null Null for blanks, comments and malformed lines.
     */
    function ioms_parse_env_line(string $line): ?array
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        $separator = strpos($trimmed, '=');
        if ($separator === false || $separator === 0) {
            return null;
        }

        $key = trim(substr($trimmed, 0, $separator));
        $value = trim(substr($trimmed, $separator + 1));

        // Strip one matching pair of surrounding quotes, if present.
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return [$key, $value];
    }

    /**
     * Read a required configuration value.
     *
     * @throws RuntimeException If the variable is absent or empty. The message names the
     *                          variable but never its value, so a misconfiguration is
     *                          diagnosable without a secret reaching a log or a screen.
     */
    function ioms_env(string $key): string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            throw new RuntimeException(
                sprintf('Required environment variable %s is not set. Copy .env.example to .env.', $key)
            );
        }

        return $value;
    }

    /**
     * Read an optional configuration value, falling back to a default.
     */
    function ioms_env_or(string $key, string $default): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    /**
     * Read an integer configuration value.
     *
     * @throws RuntimeException If the value is set but is not a valid integer — a
     *                          silently coerced timeout or size limit is worse than a
     *                          loud failure at boot.
     */
    function ioms_env_int(string $key, int $default): int
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException(sprintf('Environment variable %s must be an integer.', $key));
        }

        return (int) $value;
    }

    /**
     * Read a boolean configuration value. Accepts true/1/yes/on, case-insensitively.
     */
    function ioms_env_bool(string $key, bool $default): bool
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }
}
