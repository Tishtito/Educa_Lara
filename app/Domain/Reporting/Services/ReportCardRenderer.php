<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Enums\ReportTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Turns report-card payloads (ReportCardBuilder) into a PDF.
 *
 * dompdf, as legacy used. Each card is printed in the template it carries
 * (ReportTemplate; resources/views/reports/templates/<template>/card.blade.php
 * with that template's print stylesheet) — a live card carries the school's
 * current choice, an issued one the template it was frozen with. Locked down harder than legacy's setup,
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
            'css' => $this->stylesheets($cards),
            'logo' => $this->logoDataUri($cards[array_key_first($cards)]['school']['logo_path'] ?? null),
        ])->render();

        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The stylesheet of each template in the document, once each, in the order
     * first used. Templates prefix their classes (rp-, rm-, rf-) so they can
     * share a document: a class batch of a published exam may hold cards issued
     * under different templates.
     *
     * @param  array<array-key, array<string, mixed>>  $cards
     */
    private function stylesheets(array $cards): string
    {
        $css = [];

        foreach ($cards as $card) {
            $template = ReportTemplate::fromCard($card);
            $css[$template->value] ??= File::get($template->stylesheetPath());
        }

        return implode("\n", $css);
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
