<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password for demo staff accounts. Never a fixed value in the source code:
 * - DEMO_SEED_PASSWORD from the local .env when set (config('seeding.demo_password')), or
 * - a random password, shown once on an interactive local terminal only. It is never written
 *   to CI output, test output or redirected output, because those are kept as logs.
 */
trait GeneratesDemoPassword
{
    protected function demoPasswordHash(): string
    {
        $password = (string) config('seeding.demo_password');

        if ($password === '') {
            $password = Str::password(16);

            $interactiveLocalConsole = $this->command !== null
                && !app()->runningUnitTests()
                && getenv('CI') === false
                && !$this->command->option('no-interaction')
                && function_exists('stream_isatty') && stream_isatty(STDOUT);

            if ($interactiveLocalConsole) {
                $this->command->warn("Demo staff password for this local database (shown once, not saved): {$password}");
            }
        }

        return Hash::make($password);
    }
}
