<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A real 64x64 PNG as a data URL (made without GD). */
    private function pngDataUrl(): string
    {
        $w = $h = 64;
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0" . str_repeat("\x78\x00\x00", $w);
        }
        $chunk = fn ($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw))
            . $chunk('IEND', '');

        return 'data:image/png;base64,' . base64_encode($png);
    }

    public function test_each_staff_role_can_open_my_profile(): void
    {
        foreach (['owner', 'admin', 'coach'] as $role) {
            $user = User::where('role', $role)->first();
            $this->actingAs($user)->get(route('profile.show'))
                ->assertOk()
                ->assertSee($user->initials);
        }
    }

    public function test_photo_upload_shows_on_the_dashboard_and_remove_falls_back_to_initials(): void
    {
        $coach = User::where('role', 'coach')->first();
        $photo = $this->pngDataUrl();

        $this->actingAs($coach)->post(route('profile.photo.update'), ['photo' => $photo])
            ->assertSessionHasNoErrors();
        $this->assertSame($photo, $coach->fresh()->avatar);

        $this->actingAs($coach->fresh())->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSee($photo, false);

        $this->actingAs($coach)->delete(route('profile.photo.destroy'));
        $this->assertNull($coach->fresh()->avatar);
        $this->actingAs($coach->fresh())->get(route('coach.dashboard'))->assertSee($coach->initials);
    }

    public function test_non_image_data_is_rejected(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->post(route('profile.photo.update'), [
            'photo' => 'data:image/png;base64,' . base64_encode('<script>alert(1)</script>'),
        ])->assertSessionHasErrors('photo');

        $this->assertNull($admin->fresh()->avatar);
    }
}
