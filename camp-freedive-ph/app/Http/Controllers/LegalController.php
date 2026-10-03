<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LegalController extends Controller
{
    /**
     * Display the Terms and Conditions page.
     */
    public function terms(): View
    {
        return view('legal.terms');
    }

    /**
     * Display the Privacy Policy page.
     */
    public function privacy(): View
    {
        return view('legal.privacy');
    }
}
