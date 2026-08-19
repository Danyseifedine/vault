<?php

namespace App\Services\Files;

/**
 * Shapes decrypted file contents into something safe to put on a screen.
 *
 * A stored file is opaque once it is encrypted: the only way to check you
 * uploaded the right key was to download it again, which is a plaintext copy
 * on your disk for the sake of a glance. This returns a bounded look instead.
 *
 * It decides the kind from the BYTES, never the file name - a `.txt` holding a
 * zip is still a zip, and rendering it as text would spray control characters
 * across the page.
 */
final class FilePreview
{
    /** Enough to recognise a file, small enough to stay one round trip. */
    public const MAX_TEXT_BYTES = 131072;

    /** An image has to travel base64-encoded, so the ceiling is lower. */
    public const MAX_IMAGE_BYTES = 2097152;

    /** Leading bytes that identify an image, whatever the file is called. */
    private const IMAGE_SIGNATURES = [
        "\x89PNG\r\n\x1a\n" => 'image/png',
        "\xFF\xD8\xFF" => 'image/jpeg',
        'GIF87a' => 'image/gif',
        'GIF89a' => 'image/gif',
        'BM' => 'image/bmp',
    ];

    /**
     * @return array{
     *     kind: 'text'|'image'|'binary',
     *     contents?: string,
     *     truncated?: bool,
     *     dataUri?: string,
     *     reason?: 'not-text'|'too-large',
     * }
     */
    public function __invoke(string $contents): array
    {
        $imageType = $this->imageType($contents);

        if ($imageType !== null) {
            if (strlen($contents) > self::MAX_IMAGE_BYTES) {
                return ['kind' => 'binary', 'reason' => 'too-large'];
            }

            return [
                'kind' => 'image',
                'dataUri' => "data:{$imageType};base64,".base64_encode($contents),
            ];
        }

        if (! $this->isText($contents)) {
            return ['kind' => 'binary', 'reason' => 'not-text'];
        }

        $truncated = strlen($contents) > self::MAX_TEXT_BYTES;

        return [
            'kind' => 'text',
            'contents' => $truncated ? $this->cut($contents) : $contents,
            'truncated' => $truncated,
        ];
    }

    private function imageType(string $contents): ?string
    {
        foreach (self::IMAGE_SIGNATURES as $signature => $type) {
            if (str_starts_with($contents, (string) $signature)) {
                return $type;
            }
        }

        // WEBP is "RIFF", four size bytes, then "WEBP".
        if (str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * A NUL byte is the giveaway for binary; anything that is not valid UTF-8
     * would render as replacement characters, which tells you nothing.
     */
    private function isText(string $contents): bool
    {
        return ! str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8');
    }

    /**
     * Cutting at a byte count can land mid-character, which would make the
     * prefix invalid UTF-8 and unencodable as JSON. Drop the partial tail.
     */
    private function cut(string $contents): string
    {
        $prefix = substr($contents, 0, self::MAX_TEXT_BYTES);

        while ($prefix !== '' && ! mb_check_encoding($prefix, 'UTF-8')) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix;
    }
}
