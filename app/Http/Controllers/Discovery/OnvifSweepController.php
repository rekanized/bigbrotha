<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class OnvifSweepController extends Controller
{
    public function __invoke(): View
    {
        return view('discovery.onvif-sweep');
    }
}