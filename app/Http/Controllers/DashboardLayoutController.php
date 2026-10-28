<?php

namespace App\Http\Controllers;

use App\Services\DashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Speichert, wie ein Athlet seine Übersicht angeordnet hat.
 *
 * Antwortet mit JSON statt mit einem Redirect zurück: der würde die ganze
 * Übersicht neu rechnen (Belastung, Prognose, Kalender …), nur um eine
 * Reihenfolge zu bestätigen, die die Seite schon kennt.
 */
class DashboardLayoutController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'    => ['present', 'array'],
            'order.*'  => ['string', Rule::in(DashboardLayout::keys())],
            'hidden'   => ['present', 'array'],
            'hidden.*' => ['string', Rule::in(DashboardLayout::keys())],
        ]);

        $request->user()->update(['dashboard_layout' => [
            'order'  => DashboardLayout::order($data['order']),
            'hidden' => array_values(array_unique($data['hidden'])),
        ]]);

        return response()->json(['layout' => DashboardLayout::resolve($request->user()->dashboard_layout)]);
    }

    /** Zurück zur Vorgabe. Null statt einer Kopie, damit spätere Änderungen der Vorgabe ankommen. */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update(['dashboard_layout' => null]);

        return response()->json(['layout' => DashboardLayout::resolve(null)]);
    }
}
