<?php

namespace App\Services;

use App\Models\Document;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

class DocumentTrackingLabel
{
    private const SIZE = 120;

    /** Seal width as a share of the code. Level H error correction still reads codes with 30% covered. */
    private const SEAL_SHARE = 0.22;

    /**
     * The SDO Nueva Vizcaya seal, prepared small for QR codes (about 128 px, white background, under 10 KB) so it
     * is embedded as is. The full-size seal is images/SDO_NV.png.
     */
    private const SEAL_FILE = 'images/qr-seal.jpg';

    private static ?string $sealDataUri = null;

    /**
     * What the printed label shows: the status at printing time and the latest routing action (or the registration
     * when nothing has moved yet), in Philippine time.
     *
     * @return array{status: string, date: string, time: string, processedBy: string, processedBySignatureDataUri: ?string}
     */
    public function details(Document $document): array
    {
        $movement = $document->latestMovement;
        $actedAt = ($movement?->created_at ?? $document->created_at)->copy()->timezone('Asia/Manila');
        $processedBy = $movement
            ? ($movement->actor?->name ?? 'System')
            : ($document->client_submission_key ? 'Client' : ($document->registeredBy?->name ?? 'System'));
        $processedByUser = $movement?->actor ?? ($document->client_submission_key ? null : $document->registeredBy);

        return [
            'status' => $document->status,
            'date' => $actedAt->format('M j, Y'),
            'time' => $actedAt->format('g:i A'),
            'processedBy' => $processedBy,
            'processedBySignatureDataUri' => $this->signatureDataUri($processedByUser?->signature_path),
        ];
    }

    private function signatureDataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $mimeType = $disk->mimeType($path);
        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($disk->get($path));
    }

    public function dataUri(Document $document): string
    {
        $trackingUrl = route('tracking.index', ['tracking' => $document->tracking_number]);
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, 2),
            new SvgImageBackEnd,
        ));
        $svg = $this->withSeal($writer->writeString($trackingUrl, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::H()));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function withSeal(string $svg): string
    {
        $seal = $this->sealDataUri();
        if ($seal === '') {
            return $svg;
        }

        $sealSize = round(self::SIZE * self::SEAL_SHARE, 2);
        $sealAt = round((self::SIZE - $sealSize) / 2, 2);
        $center = self::SIZE / 2;

        // A white circle keeps the round seal clear of the surrounding modules; the seal (a JPEG, so it has
        // white corners) is clipped to a circle so only the round backing shows.
        $overlay = sprintf(
            '<clipPath id="dts-seal"><circle cx="%1$s" cy="%1$s" r="%6$s"/></clipPath>'
            .'<circle cx="%1$s" cy="%1$s" r="%2$s" fill="#ffffff"/>'
            .'<image x="%3$s" y="%3$s" width="%4$s" height="%4$s" href="%5$s" clip-path="url(#dts-seal)" preserveAspectRatio="xMidYMid meet"/>',
            $center, round($sealSize / 2 + 2, 2), $sealAt, $sealSize, $seal, round($sealSize / 2, 2),
        );

        return str_replace('</svg>', $overlay.'</svg>', $svg);
    }

    /**
     * Read once per request (a sheet of labels builds many codes); empty when the file is missing, and the code is
     * then printed without a seal.
     */
    private function sealDataUri(): string
    {
        if (self::$sealDataUri !== null) {
            return self::$sealDataUri;
        }

        $path = public_path(self::SEAL_FILE);

        return self::$sealDataUri = is_file($path)
            ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($path))
            : '';
    }
}
