<?php

namespace App\Http\Controllers\Frontend;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Tobuli\Entities\Page;

class PagesController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $content = Page::firstWhere('slug', $slug)->content ?? '';

        $view = $request->ajax()
            ? 'front::Docs.modal'
            : 'front::Docs.show';

        return view($view, compact('content'));
    }
}
