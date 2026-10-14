<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The demo seeders contain no password: they use DEMO_SEED_PASSWORD (config seeding.demo_password)
 * or a random one, never print it outside an interactive local terminal, and refuse production.
 */
class SeederCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDERS = [DatabaseSeeder::class => 'DatabaseSeeder.php', DemoSeeder::class => 'DemoSeeder.php'];

    /** String literals passed to Hash::make(...), read with the tokenizer (files are not executed). */
    private function hashMakeLiterals(string $file): int
    {
        $tokens = array_values(array_filter(token_get_all(file_get_contents(database_path("seeders/{$file}"))), fn ($t) => !is_array($t) || $t[0] !== T_WHITESPACE));
        $count = 0;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && strcasecmp($t[1], 'make') === 0
                && ($tokens[$i + 1] ?? null) === '('
                && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
                $count++;
            }
        }

        return $count;
    }

    private function staff(): \Illuminate\Support\Collection
    {
        return User::whereIn('role', ['owner', 'admin', 'coach'])->get();
    }

    /** SD-01 */
    public function test_seeders_contain_no_password_literal(): void
    {
        foreach (self::SEEDERS as $file) {
            $this->assertSame(0, $this->hashMakeLiterals($file), "{$file} passes a literal to Hash::make()");
        }
    }

    /** SD-02 */
    public function test_seeded_staff_use_the_configured_local_password(): void
    {
        foreach (self::SEEDERS as $seeder => $file) {
            $this->seed($seeder);
            $staff = $this->staff();
            $this->assertNotEmpty($staff, $file);
            foreach ($staff as $user) {
                $this->assertTrue(Hash::check($this->demoPassword, $user->password), "{$file}: {$user->role}");
            }
        }
    }

    /** SD-03 */
    public function test_without_a_configured_password_each_run_gets_a_new_random_one_and_prints_nothing(): void
    {
        config(['seeding.demo_password' => null]);

        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $output = Artisan::output();
        $first = $this->staff()->firstWhere('role', 'owner');

        $this->assertStringNotContainsString('Demo staff password', $output, 'never printed outside an interactive local terminal');
        $this->assertFalse(Hash::check($this->demoPassword, $first->password), 'not the configured value');
        $this->assertFalse(Hash::check('', $first->password));

        // A second run uses a different random password: the first run's accounts are replaced
        // and no fixed value exists that both could share. (Hashes are compared, values unknown.)
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $second = $this->staff()->firstWhere('role', 'owner');
        $this->assertNotSame($first->password, $second->password);
    }

    /** SD-04 */
    public function test_seeders_still_refuse_production(): void
    {
        $this->app['env'] = 'production';
        try {
            foreach (array_keys(self::SEEDERS) as $seeder) {
                try {
                    $this->app->make($seeder)->run();
                    $this->fail("{$seeder} ran in production");
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('cannot run in production', $e->getMessage());
                }
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(0, User::count());
    }
}
