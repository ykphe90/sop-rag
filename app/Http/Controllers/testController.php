<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class testController extends Controller
{
    public function test(Request $request)
    {
        $sopPath = base_path('public/SOP-001-食品安全与卫生管理.md');
        dd($sopPath);
    }
}