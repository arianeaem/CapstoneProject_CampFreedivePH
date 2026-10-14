<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Demo seeders empty the tables (including users and audit_logs) and create staff
 * accounts with a shared, published password, so they must never run in production.
 */
trait RefusesProduction
{
    protected function refuseInProduction(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(static::class . ' creates demo staff accounts with a default password and clears existing data. It cannot run in production.');
        }
    }
}
