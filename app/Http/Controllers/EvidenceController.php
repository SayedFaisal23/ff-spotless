<?php

namespace App\Http\Controllers;

use App\Models\DailyTaskEvidence;
use App\Models\WeeklyTaskEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function daily(DailyTaskEvidence $evidence): StreamedResponse
    {
        return $this->response($evidence->disk, $evidence->path, $evidence->mime_type);
    }

    public function weekly(WeeklyTaskEvidence $evidence): StreamedResponse
    {
        return $this->response($evidence->disk, $evidence->path, $evidence->mime_type);
    }

    private function response(string $disk, string $path, string $mime): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response(
            $path,
            'bukti.'.match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            },
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
