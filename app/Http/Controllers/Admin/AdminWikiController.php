<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WikiChangelog;
use App\Models\WikiPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AdminWikiController extends Controller
{
    private const CATEGORIES = [
        'architecture' => ['label' => 'Architektur & Tech Stack',   'icon' => 'server'],
        'features'     => ['label' => 'Features',                   'icon' => 'sparkles'],
        'api'          => ['label' => 'API & Services',             'icon' => 'code'],
        'decisions'    => ['label' => 'Entscheidungs-Log (ADR)',    'icon' => 'document'],
    ];

    public function index()
    {
        $pages = WikiPage::orderBy('category')->orderBy('sort_order')->orderBy('title')->get();

        $grouped = collect(self::CATEGORIES)->map(function ($meta, $cat) use ($pages) {
            return array_merge($meta, [
                'key'   => $cat,
                'pages' => $pages->where('category', $cat)->values(),
            ]);
        })->values();

        return Inertia::render('Admin/Wiki/Index', [
            'grouped'    => $grouped,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function page(WikiPage $page)
    {
        return Inertia::render('Admin/Wiki/Page', [
            'page'       => $page,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'    => 'required|string|max:120',
            'category' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'content'  => 'nullable|string',
        ]);

        $page = WikiPage::create([
            'slug'       => Str::slug($data['title']) . '-' . Str::random(4),
            'category'   => $data['category'],
            'title'      => $data['title'],
            'content'    => $data['content'] ?? '',
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('admin.wiki.page', $page)->with('success', 'Seite erstellt.');
    }

    public function update(Request $request, WikiPage $page)
    {
        $data = $request->validate([
            'title'      => 'required|string|max:120',
            'category'   => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'content'    => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $page->update([
            'title'      => $data['title'],
            'category'   => $data['category'],
            'content'    => $data['content'] ?? '',
            'sort_order' => $data['sort_order'] ?? $page->sort_order,
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'Gespeichert.');
    }

    public function destroy(WikiPage $page)
    {
        $page->delete();
        return redirect()->route('admin.wiki.index')->with('success', 'Seite gelöscht.');
    }

    public function changelog()
    {
        $entries = WikiChangelog::orderByDesc('pushed_at')->paginate(20);

        return Inertia::render('Admin/Wiki/Changelog', [
            'entries' => $entries,
        ]);
    }
}
