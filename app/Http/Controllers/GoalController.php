<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->goals()->orderByDesc('created_at')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:goals,id',
            'name' => 'required|string|max:150',
            'type' => 'required|string|max:50',
            'target_value' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:20',
            'target_time_hours' => 'required|integer|min:0|max:23',
            'target_time_minutes' => 'required|integer|min:0|max:59',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'meta' => 'nullable|array',
            'active' => 'nullable|boolean',
        ]);

        // No need to convert empty strings since fields are now required

        $data['user_id'] = $request->user()->id;
        $data['active'] = $data['active'] ?? true;

        if (! empty($data['id'])) {
            $goal = $request->user()->goals()->findOrFail($data['id']);
            $goal->update($data);
            $message = 'Ziel aktualisiert!';
        } else {
            $goal = $request->user()->goals()->create($data);
            $message = 'Ziel hinzugefügt!';
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Request $request, Goal $goal)
    {
        if ($goal->user_id !== $request->user()->id) {
            abort(403);
        }

        $goal->delete();

        return redirect()->back()->with('success', 'Ziel gelöscht!');
    }
}
