<?php

namespace App\Http\Controllers\Admin\Front;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ArticleController extends Controller
{
    public function index()
    {
        $articles = Article::latest()->paginate(15);
        return view('admin.articles.index', compact('articles'));
    }

    public function create()
    {
        return view('admin.articles.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'image' => 'nullable|image|max:2048',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ]);

        $data['slug'] = Str::slug($data['title']);
        $data['user_id'] = auth()->id();

        if (!empty($data['is_published'])) {
            $data['is_published'] = true;
            $data['published_at'] = $data['published_at'] ?? now();
        } else {
            $data['is_published'] = false;
            $data['published_at'] = null;
        }

        // handle image upload
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('articles', 'public');
            $data['image'] = $path;
        } elseif ($request->filled('image')) {
            // image from file manager (string URL or path)
            $data['image'] = $request->input('image');
        }

        Article::create($data);

        return redirect()->route('admin.articles.index')->with('success', 'Article created successfully');
    }

    public function edit(Article $article)
    {
        return view('admin.articles.edit', compact('article'));
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'image' => 'nullable|image|max:2048',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ]);

        if ($article->title !== $data['title']) {
            $data['slug'] = Str::slug($data['title']);
        }

        if (!empty($data['is_published'])) {
            $data['is_published'] = true;
            $data['published_at'] = $data['published_at'] ?? now();
        } else {
            $data['is_published'] = false;
            $data['published_at'] = null;
        }

        // handle image upload or filemanager path
        if ($request->hasFile('image')) {
            // delete old if stored locally
            if ($article->image && !Str::startsWith($article->image, ['http://', 'https://'])) {
                Storage::disk('public')->delete($article->image);
            }
            $path = $request->file('image')->store('articles', 'public');
            $data['image'] = $path;
        } elseif ($request->filled('image')) {
            // if image supplied as string (from LFM), delete old local file if exists
            if ($article->image && !Str::startsWith($article->image, ['http://', 'https://'])) {
                Storage::disk('public')->delete($article->image);
            }
            $data['image'] = $request->input('image');
        }

        $article->update($data);

        return redirect()->route('admin.articles.index')->with('success', 'Article updated successfully');
    }

    public function destroy(Article $article)
    {
        $article->delete();
        return back()->with('success', 'Article deleted');
    }
}
