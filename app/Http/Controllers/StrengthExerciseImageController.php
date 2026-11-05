<?php

namespace App\Http\Controllers;

use App\Models\StrengthExercise;
use App\Models\StrengthExerciseImage;
use Illuminate\Http\Response;

/**
 * Liefert das Bild einer Kraftübung aus der Datenbank. Der Link trägt den
 * Zeitstempel des Bildes (`?v=`), deshalb darf der Browser es lange behalten:
 * ein neues Bild hat einen neuen Link.
 */
class StrengthExerciseImageController extends Controller
{
    public function __invoke(StrengthExercise $exercise): Response
    {
        $image = StrengthExerciseImage::where('strength_exercise_id', $exercise->id)->first();
        abort_unless($image, 404);

        return response(base64_decode($image->data), 200, [
            'Content-Type'  => $image->mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
