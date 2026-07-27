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
                'photos' => 'Pemprosesan watermark foto tidak tersedia. Sila aktifkan extension PHP GD dan EXIF di server.',
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

        $oriented = $this->applyOrientation($image, $mime, $source);

        if ($oriented !== $image) {
            imagedestroy($image);
            $image = $oriented;
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
            || ! function_exists('imagecreatetruecolor')
            || ! function_exists('imagecopy')
            || ! function_exists('imagecopyresampled')
            || ! function_exists('imageflip')
            || ! function_exists('imagestring')
            || ! function_exists('imagefilledrectangle')
            || ! function_exists('imagerotate')
        ) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => function_exists('imagejpeg') && function_exists('exif_read_data'),
            'image/png' => function_exists('imagepng'),
            'image/webp' => function_exists('imagewebp'),
            default => true,
        };
    }

    private function drawWatermark(object $image, string $text): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $font = 5;
        $baseWidth = imagefontwidth($font) * strlen($text) + 2;
        $baseHeight = imagefontheight($font) + 2;
        $padding = max(8, min(64, (int) floor(min($width, $height) / 35)));
        $maxScale = max(1, (int) floor(($width - ($padding * 2)) / $baseWidth));
        $scale = max(1, min(8, $maxScale, (int) floor(min($width, $height) / 350) + 1));
        $textWidth = $baseWidth * $scale;
        $textHeight = $baseHeight * $scale;
        $x = $padding;
        $y = max($padding, $height - $textHeight - $padding);

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $background = imagecolorallocatealpha($image, 0, 0, 0, 30);

        imagefilledrectangle(
            $image,
            max(0, $x - $padding),
            max(0, $y - $padding),
            min($width - 1, $x + $textWidth + $padding),
            min($height - 1, $y + $textHeight + $padding),
            $background,
        );

        $stamp = imagecreatetruecolor($baseWidth, $baseHeight);
        imagealphablending($stamp, false);
        imagesavealpha($stamp, true);

        $transparent = imagecolorallocatealpha($stamp, 0, 0, 0, 127);
        $shadow = imagecolorallocatealpha($stamp, 0, 0, 0, 0);
        $foreground = imagecolorallocatealpha($stamp, 255, 255, 255, 0);

        imagefilledrectangle($stamp, 0, 0, $baseWidth, $baseHeight, $transparent);
        imagestring($stamp, $font, 2, 2, $text, $shadow);
        imagestring($stamp, $font, 1, 1, $text, $foreground);

        if ($scale === 1) {
            imagecopy($image, $stamp, $x, $y, 0, 0, $baseWidth, $baseHeight);
            imagedestroy($stamp);

            return;
        }

        $scaledStamp = imagecreatetruecolor($textWidth, $textHeight);
        imagealphablending($scaledStamp, false);
        imagesavealpha($scaledStamp, true);
        $scaledTransparent = imagecolorallocatealpha($scaledStamp, 0, 0, 0, 127);
        imagefilledrectangle($scaledStamp, 0, 0, $textWidth, $textHeight, $scaledTransparent);
        imagecopyresampled($scaledStamp, $stamp, 0, 0, 0, 0, $textWidth, $textHeight, $baseWidth, $baseHeight);
        imagecopy($image, $scaledStamp, $x, $y, 0, 0, $textWidth, $textHeight);

        imagedestroy($scaledStamp);
        imagedestroy($stamp);
    }

    private function applyOrientation(object $image, string $mime, string $source): object
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0) ?: $image,
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), -90),
            6 => imagerotate($image, -90, 0) ?: $image,
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    private function flip(object $image, int $mode): object
    {
        imageflip($image, $mode);

        return $image;
    }

    private function rotate(object $image, int $angle): object
    {
        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        return $rotated;
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
