<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Profile" for coaches, admins and owners: upload or remove a profile photo.
 *
 * The browser crops and resizes the photo to a small square before uploading, and it is
 * saved in the database (files in storage/ are wiped by every Azure deploy).
 */
class ProfileController extends Controller
{
    /** Longest accepted data URL (~300 KB image). The browser sends ~20-40 KB. */
    private const MAX_DATA_URL_LENGTH = 400_000;

    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }

    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'string', 'max:' . self::MAX_DATA_URL_LENGTH, 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ], [
            'photo.required' => 'Please choose a photo.',
            'photo.max' => 'The photo is too large. Please choose a smaller image.',
            'photo.regex' => 'Please upload a JPG, PNG or WebP image.',
        ]);

        // Make sure it really is an image, not just text that looks like one
        $binary = base64_decode(substr($request->input('photo'), strpos($request->input('photo'), ',') + 1), true);
        $info = $binary !== false ? @getimagesizefromstring($binary) : false;
        if (!$info || $info[0] < 32 || $info[1] < 32 || $info[0] > 1024 || $info[1] > 1024) {
            return back()->withErrors(['photo' => 'That file is not a valid image. Please try another photo.']);
        }

        $request->user()->update(['avatar' => $request->input('photo')]);

        return back()->with('success', 'Profile photo updated.');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $request->user()->update(['avatar' => null]);

        return back()->with('success', 'Profile photo removed. Your initials will be shown instead.');
    }
}
