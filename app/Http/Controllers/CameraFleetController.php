<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class CameraFleetController extends Controller
{
    public function __invoke(): View
    {
        return view('camera-fleet.index');
    }
}