<?php

/**
 * PDO connection factory.
 *
 * Every setting here is load-bearing for a guarantee stated elsewhere:
 *
 * - ERRMODE_EXCEPTION       a failed statement raises rather than returning false, so a
 *                           transaction cannot half-succeed unnoticed (Constitution V).
 * - EMULATE_PREPARES=false  real server-side prepared statements. With emulation on, PDO
 *                           interpolates client-side and the injection guarantee is
 *                           weaker than it looks (Constitution VI).
 * - STRINGIFY_FETCHES=false integers come back as PHP integers. Stock arithmetic on
 *                           numeric strings is exactly the coercion class that
 *                           strict_types exists to prevent (Constitution II).
 * - FETCH_ASSOC             one row shape everywhere, so repositories map explicitly.
 * - utf8mb4                 matches the schema's charset.
 *
 * No credential is written here. Values come from the environment, and a failure names
 * the variable, never its value.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

if (!function_exists('ioms_pdo')) {
    /**
     * Build a PDO connection for the application database.
     *
     * @param string|null $database Override the database name. Used by the integration
     *                              test bootstrap to reach the throwaway test schema
     *                              rather than the working one.
     *
     * @throws RuntimeException If the connection cannot be established. The original
     *                          PDOException is attached as the cause for the server-side
     *                          log, but its message — which can carry the DSN, the user
     *                          and the host — is never surfaced to a caller (FR-067).
     */
    function ioms_pdo(?string $database = null): PDO
    {
        $host = ioms_env('DB_HOST');
        $port = ioms_env_int('DB_PORT', 3306);
        $name = $database ?? ioms_env('DB_DATABASE');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            return new PDO(
                $dsn,
                ioms_env('DB_USERNAME'),
                ioms_env('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Unable to connect to the database. Check the DB_* values in .env.',
                0,
                $exception
            );
        }
    }
}
