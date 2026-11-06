<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\CorosProbe;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Die Rohantworten von `coros:probe` zum Herunterladen. Bewusst ohne Vue:
 * eine Liste und Download-Links, solange der Import gebaut wird.
 */
class AdminCorosProbeController extends Controller
{
    public function index()
    {
        $files = collect(Storage::disk('local')->files(CorosProbe::DIR))
            ->sortDesc()
            ->map(fn ($path) => basename($path))
            ->values();

        $rows = $files->map(fn ($name) => sprintf(
            '<li><a href="%s">%s</a></li>',
            e(route('admin.coros-probe.show', $name)),
            e($name),
        ))->implode('');

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>COROS-Probe</title><body style="font:16px system-ui;padding:16px">'
            . '<h1>COROS-Rohantworten</h1>'
            . ($rows ? "<ul>{$rows}</ul>" : '<p>Noch nichts. <code>php artisan coros:probe &lt;user-id&gt;</code></p>'));
    }

    public function show(string $file)
    {
        abort_unless(preg_match('/^user\d+_[\d_-]+\.(txt|fit)$/', $file), 404);
        $path = CorosProbe::DIR . '/' . $file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }
}
