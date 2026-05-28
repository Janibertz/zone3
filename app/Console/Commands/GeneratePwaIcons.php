<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GeneratePwaIcons extends Command
{
    protected $signature   = 'pwa:icons';
    protected $description = 'Generate PWA icon PNG files in public/icons/';

    public function handle(): int
    {
        if (!extension_loaded('gd')) {
            $this->error('GD extension not available.');
            return 1;
        }

        $dir = public_path('icons');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sizes = [72, 192, 512];

        foreach ($sizes as $size) {
            $this->makeIcon($dir, $size);
            $this->info("Generated icon-{$size}.png");
        }

        $this->info('PWA icons generated successfully.');
        return 0;
    }

    private function makeIcon(string $dir, int $size): void
    {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);

        // Indigo background (#6366f1)
        $bg = imagecolorallocate($img, 99, 102, 241);
        imagefill($img, 0, 0, $bg);

        // Rounded corners via alpha masking
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $radius = (int) ($size * 0.18);
        $this->applyRoundedCorners($img, $size, $radius, $transparent);

        // White text "Z3"
        $white = imagecolorallocate($img, 255, 255, 255);
        $font  = 5; // built-in GD font

        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $text  = 'Z3';
        $scale = (int) floor($size / 48);
        $scale = max(1, $scale);

        // Draw scaled text by rendering to temp image and resampling
        $tmpW = $charW * strlen($text);
        $tmpH = $charH;
        $tmp  = imagecreatetruecolor($tmpW, $tmpH);
        $tmpBg = imagecolorallocate($tmp, 99, 102, 241);
        imagefill($tmp, 0, 0, $tmpBg);
        $tmpWhite = imagecolorallocate($tmp, 255, 255, 255);
        imagestring($tmp, $font, 0, 0, $text, $tmpWhite);

        $dstW = $tmpW * $scale;
        $dstH = $tmpH * $scale;
        $dstX = (int) (($size - $dstW) / 2);
        $dstY = (int) (($size - $dstH) / 2);

        imagecopyresampled($img, $tmp, $dstX, $dstY, 0, 0, $dstW, $dstH, $tmpW, $tmpH);
        imagedestroy($tmp);

        imagepng($img, "{$dir}/icon-{$size}.png");
        imagedestroy($img);
    }

    private function applyRoundedCorners($img, int $size, int $r, $transparent): void
    {
        // Top-left
        for ($x = 0; $x < $r; $x++) {
            for ($y = 0; $y < $r; $y++) {
                if (($x - $r) ** 2 + ($y - $r) ** 2 > $r ** 2) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
        // Top-right
        for ($x = $size - $r; $x < $size; $x++) {
            for ($y = 0; $y < $r; $y++) {
                if (($x - ($size - $r)) ** 2 + ($y - $r) ** 2 > $r ** 2) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
        // Bottom-left
        for ($x = 0; $x < $r; $x++) {
            for ($y = $size - $r; $y < $size; $y++) {
                if (($x - $r) ** 2 + ($y - ($size - $r)) ** 2 > $r ** 2) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
        // Bottom-right
        for ($x = $size - $r; $x < $size; $x++) {
            for ($y = $size - $r; $y < $size; $y++) {
                if (($x - ($size - $r)) ** 2 + ($y - ($size - $r)) ** 2 > $r ** 2) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
    }
}
