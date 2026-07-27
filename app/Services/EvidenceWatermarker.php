<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class EvidenceWatermarker
{
    /**
     * @return array{contents: string, mime_type: string, extension: string, size_bytes: int}
     */
    public function watermark(UploadedFile $photo, string $text): array
    {
        $mime = (string) $photo->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages(['photos' => 'Format foto bukti tidak disokong.']);
        }

        if (! $this->isAvailable($mime)) {
            throw ValidationException::withMessages([
                'photos' => 'Pemprosesan watermark foto tidak tersedia. Sila aktifkan extension PHP GD di server.',
            ]);
        }

        $source = $photo->getRealPath();
        $contents = is_string($source) ? file_get_contents($source) : false;

        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['photos' => 'Foto bukti tidak dapat dibaca.']);
        }

        $image = @imagecreatefromstring($contents);

        if (! is_object($image)) {
            throw ValidationException::withMessages(['photos' => 'Bukti mestilah fail imej yang sah.']);
        }

        $this->drawWatermark($image, $text);
        $watermarked = $this->encode($image, $mime);
        imagedestroy($image);

        if ($watermarked === '') {
            throw ValidationException::withMessages(['photos' => 'Foto bukti tidak dapat diproses.']);
        }

        return [
            'contents' => $watermarked,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($watermarked),
        ];
    }

    public function isAvailable(?string $mime = null): bool
    {
        if (
            ! extension_loaded('gd')
            || ! function_exists('imagecreatefromstring')
            || ! function_exists('imagestring')
            || ! function_exists('imagefilledrectangle')
        ) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => function_exists('imagejpeg'),
            'image/png' => function_exists('imagepng'),
            'image/webp' => function_exists('imagewebp'),
            default => true,
        };
    }

    private function drawWatermark(object $image, string $text): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $font = $width < 260 ? 2 : 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        $padding = max(4, min(12, (int) floor(min($width, $height) / 30)));
        $x = $padding;
        $y = max($padding, $height - $textHeight - $padding);

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $background = imagecolorallocatealpha($image, 0, 0, 0, 45);
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, 0);
        $foreground = imagecolorallocatealpha($image, 255, 255, 255, 0);

        imagefilledrectangle(
            $image,
            max(0, $x - $padding),
            max(0, $y - $padding),
            min($width - 1, $x + $textWidth + $padding),
            min($height - 1, $y + $textHeight + $padding),
            $background,
        );
        imagestring($image, $font, $x + 1, $y + 1, $text, $shadow);
        imagestring($image, $font, $x, $y, $text, $foreground);
    }

    private function encode(object $image, string $mime): string
    {
        ob_start();
        $success = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
            default => false,
        };
        $contents = ob_get_clean();

        return $success && is_string($contents) ? $contents : '';
    }
}
