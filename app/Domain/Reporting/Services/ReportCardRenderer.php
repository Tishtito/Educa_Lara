<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Turns report-card payloads (ReportCardBuilder) into a PDF.
 *
 * dompdf, as legacy used, rendering resources/views/reports/card.blade.php
 * with the ported print stylesheet. Locked down harder than legacy's setup,
 * which enabled isRemoteEnabled: report cards contain text typed by teachers,
 * and a renderer that fetches remote URLs from its input is a server-side
 * request forgery hole. Here remote loading, embedded PHP and JavaScript are
 * off, the filesystem is chrooted to the report views, and the only image — the
 * school logo — is read from the school's own storage and inlined.
 */
class ReportCardRenderer
{
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    private const LOGO_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /** @param list<array<string, mixed>> $cards */
    public function pdf(array $cards): string
    {
        if ($cards === []) {
            throw new InvalidArgumentException('No report cards to render.');
        }

        $html = view('reports.document', [
            'cards' => array_values($cards),
            'css' => File::get(resource_path('views/reports/report-card.css')),
            'logo' => $this->logoDataUri($cards[array_key_first($cards)]['school']['logo_path'] ?? null),
        ])->render();

        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function options(): Options
    {
        $work = storage_path('framework/dompdf');
        File::ensureDirectoryExists($work);

        return (new Options)
            ->setIsRemoteEnabled(false)
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setChroot([resource_path('views/reports')])
            ->setDefaultFont('Helvetica')
            // dompdf's defaults write into vendor/, which the web user cannot
            // (and should not) write to in production.
            ->setTempDir($work)
            ->setFontCache($work)
            ->setFontDir($work);
    }

    /** The school logo as a data URI, or null if unset, missing or not a small image. */
    private function logoDataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path) || $disk->size($path) > self::MAX_LOGO_BYTES) {
            return null;
        }

        $mime = $disk->mimeType($path);

        if (! in_array($mime, self::LOGO_MIME_TYPES, true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }
}
