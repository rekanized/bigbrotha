<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class SetupController extends Controller
{
    public function __invoke(): View
    {
        return view('setup.index');
    }
}
