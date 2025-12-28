<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    /**
     * Display the specified resource.
     *
     * @param  string  $slug
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        $article = Article::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // Pass as model, let the view handle it (we fixed the view to use array or object access correctly, 
        // but for a new view we should stick to object access or consistent toArray if we want)
        // Since we are making a NEW view, we will use object access $article->title in the view.
        return view('blog.show', compact('article'));
    }
}
