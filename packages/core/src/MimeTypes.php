<?php

declare(strict_types=1);

namespace Switon\Core;

use function pathinfo;
use function str_contains;
use function strtolower;
use function strstr;

/**
 * Extension → MIME map (no I/O). Accepts a path or URL; query string ignored.
 */
class MimeTypes
{
    /** @param string $name Path or URL (e.g. `https://…/a.png?x=1`); uses the extension only. */
    public static function guess(string $name): ?string
    {
        if (str_contains($name, '?')) {
            $name = strstr($name, '?', true) ?: $name;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }

        return match ($ext) {
            // image (MDN common / browser-safe + still common)
            'png' => 'image/png',
            'apng' => 'image/apng',
            'jpg', 'jpeg', 'jfif', 'pjpeg', 'pjp' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg', 'svgz' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'tif', 'tiff' => 'image/tiff',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'avif' => 'image/avif',
            'jxl' => 'image/jxl',
            // video — omit `.ts` (MPEG-TS): clashes with TypeScript sources in this stack
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'mpeg', 'mpg' => 'video/mpeg',
            'm4v' => 'video/x-m4v',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            '3gp', '3gpp' => 'video/3gpp',
            '3g2' => 'video/3gpp2',
            'ogv' => 'video/ogg',
            // audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'opus' => 'audio/opus',
            'weba' => 'audio/webm',
            'pcm' => 'audio/pcm',
            'mpga' => 'audio/mpeg',
            'mid', 'midi' => 'audio/midi',
            'aif', 'aiff' => 'audio/aiff',
            'caf' => 'audio/x-caf',
            'wma' => 'audio/x-ms-wma',
            // Office OOXML
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            // legacy Office
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            // OpenDocument
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            // documents / data
            'pdf' => 'application/pdf',
            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'mjs' => 'text/javascript',
            'xml' => 'application/xml',
            'yaml', 'yml' => 'application/yaml',
            // archives
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'gz' => 'application/gzip',
            'tgz' => 'application/gzip',
            'tar' => 'application/x-tar',
            default => null,
        };
    }
}
