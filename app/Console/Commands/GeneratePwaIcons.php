<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Das App-Icon, wie es auf dem Homescreen landet.
 *
 * Vorher: Indigo-Grund mit „Z3" in GDs eingebauter Bitmap-Schrift, um den
 * Faktor 4–10 hochgerechnet. Hochskalierte Bitmap-Glyphen werden unscharf,
 * und Indigo war die Farbe der abgeloesten enduco-Richtung.
 *
 * Zwei Fehler steckten ausserdem in der Form:
 *
 * **Runde Ecken waren eingebacken.** iOS legt bei einem `apple-touch-icon`
 * IMMER seine eigene Maske darueber, Android ebenso bei `maskable`. Ein
 * vorgerundetes Icon wird dadurch ein zweites Mal beschnitten — sichtbar als
 * abgeschnittene Ecken mit transparenten Luecken dahinter. Das Icon ist
 * deshalb jetzt ein randloses Quadrat ohne Transparenz; das Runden
 * uebernimmt das Betriebssystem.
 *
 * **Dieselbe Datei stand als `any` UND `maskable` im Manifest.** Das ist nur
 * dann richtig, wenn das Motiv in der Sicherheitszone liegt: Android
 * beschneidet eine maskable Grafik auf etwa 80 % der Kantenlaenge. Die
 * Balken sitzen jetzt in den mittleren 58 %, also mit Luft nach allen
 * Seiten, und die Doppelangabe stimmt.
 *
 * Gezeichnet wird das Motiv vierfach vergroessert und danach herunter-
 * gerechnet — GD kennt kein Antialiasing fuer gefuellte Formen, und das
 * Herunterrechnen liefert es umsonst.
 */
class GeneratePwaIcons extends Command
{
    protected $signature   = 'pwa:icons';
    protected $description = 'Generate PWA icon PNG files in public/icons/';

    /** Akzentblau wie `--z-accent` im hellen Thema. */
    private const BG = [0, 113, 227];

    /** Faktor, um den ueberabgetastet wird. */
    private const SUPERSAMPLE = 4;

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('GD extension not available.');

            return 1;
        }

        $dir = public_path('icons');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ([72, 192, 512] as $size) {
            $this->makeIcon($dir, $size);
            $this->info("Generated icon-{$size}.png");
        }

        $this->info('PWA icons generated successfully.');

        return 0;
    }

    private function makeIcon(string $dir, int $size): void
    {
        $big = $size * self::SUPERSAMPLE;

        $img = imagecreatetruecolor($big, $big);
        $bg  = imagecolorallocate($img, ...self::BG);
        imagefill($img, 0, 0, $bg);

        $this->drawMark($img, $big);

        // Herunterrechnen — hier entsteht die weiche Kante.
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);
        imagedestroy($img);

        imagepng($out, "{$dir}/icon-{$size}.png");
        imagedestroy($out);
    }

    /**
     * Drei aufsteigende Balken — dasselbe Bild wie im Wochendiagramm und auf
     * dem Aktivitaeten-Reiter.
     *
     * Ein Schriftzug waere bei 48 px auf dem Homescreen nicht mehr zu lesen;
     * eine Form ist es. Und sie sagt etwas: steigender Umfang.
     */
    private function drawMark($img, int $canvas): void
    {
        $white = imagecolorallocate($img, 255, 255, 255);

        // Sicherheitszone: Motiv in den mittleren 58 % der Kantenlaenge.
        $markW = (int) round($canvas * 0.58);
        $left  = (int) round(($canvas - $markW) / 2);

        $barW  = (int) round($markW * 0.22);
        $gap   = (int) round(($markW - 3 * $barW) / 2);
        $base  = (int) round($canvas * 0.72);   // gemeinsame Grundlinie
        $tall  = (int) round($canvas * 0.44);   // Hoehe des hoechsten Balkens

        foreach ([0.45, 0.72, 1.0] as $i => $factor) {
            $h   = (int) round($tall * $factor);
            $x   = $left + $i * ($barW + $gap);
            $top = $base - $h;
            $r   = (int) round($barW / 2);

            // Schaft zwischen den runden Enden …
            imagefilledrectangle($img, $x, $top + $r, $x + $barW, $base - $r, $white);
            // … und die Kappen oben und unten.
            imagefilledellipse($img, $x + $r, $top + $r, $barW, $barW, $white);
            imagefilledellipse($img, $x + $r, $base - $r, $barW, $barW, $white);
        }
    }
}
