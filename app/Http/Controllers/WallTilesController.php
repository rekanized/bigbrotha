<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class WallTilesController extends Controller
{
    public function __invoke(): View
    {
        return view('wall-tiles.index');
    }
}
