<?php

namespace App\Services\Media;

use RuntimeException;

class JpegConverter
{
    public const QUALITY = 90;

    /**
     * Turn an image file (PNG, GIF, WebP, ...) into JPEG bytes; transparent parts become white.
     */
    public function toJpeg(string $absolutePath): string
    {
        $contents = @file_get_contents($absolutePath);
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('The image could not be read for JPEG conversion.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new RuntimeException('The image could not be converted to JPEG.');
        }

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
