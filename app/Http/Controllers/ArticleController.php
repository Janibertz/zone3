<?php

namespace App\Http\Controllers;

use App\Services\Content\Articles;
use Illuminate\Http\Response;

/**
 * Ratgeber und Blog als Blade-Seiten, nicht als Inertia: Suchmaschinen sollen
 * den Text im ersten HTML finden, und zone3.run rendert Vue nur im Browser.
 */
class ArticleController extends Controller
{
    public function index(string $kind)
    {
        abort_unless(isset(Articles::KINDS[$kind]), 404);

        return view('content.index', [
            'kind'     => $kind,
            'section'  => Articles::KINDS[$kind],
            'articles' => Articles::all($kind),
            'other'    => collect(Articles::KINDS)->except($kind),
        ]);
    }

    public function show(string $kind, string $slug)
    {
        abort_unless(isset(Articles::KINDS[$kind]), 404);
        $article = Articles::find($kind, $slug) ?? abort(404);

        return view('content.show', [
            'kind'    => $kind,
            'section' => Articles::KINDS[$kind],
            'article' => $article,
            'more'    => Articles::all($kind)->where('slug', '!=', $slug)->take(3)->values(),
        ]);
    }

    public function sitemap(): Response
    {
        $urls = collect([url('/'), route('privacy'), route('impressum')]);
        foreach (array_keys(Articles::KINDS) as $kind) {
            $urls->push(route('articles.index', $kind));
            $urls = $urls->merge(Articles::all($kind)->pluck('url'));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls->map(fn ($u) => '  <url><loc>' . e($u) . '</loc></url>')->implode("\n")
            . "\n</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
