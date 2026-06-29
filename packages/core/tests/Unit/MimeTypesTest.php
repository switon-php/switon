<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Core\MimeTypes;

#[CoversClass(MimeTypes::class)]
class MimeTypesTest extends TestCase
{
    public function testGuessKnownExtensions(): void
    {
        $this->assertSame('image/png', MimeTypes::guess('a/b.png'));
        $this->assertSame('image/gif', MimeTypes::guess('anim.gif'));
        $this->assertSame('image/webp', MimeTypes::guess('hero.webp'));
        $this->assertSame('image/jpeg', MimeTypes::guess('x.JPG'));
        $this->assertSame('video/mp4', MimeTypes::guess('clip.mp4'));
        $this->assertSame('audio/mpeg', MimeTypes::guess('t.mp3'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            MimeTypes::guess('report.docx'),
        );
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            MimeTypes::guess('sheet.xlsx'),
        );
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            MimeTypes::guess('deck.pptx'),
        );
        $this->assertSame('application/msword', MimeTypes::guess('legacy.doc'));
        $this->assertSame('application/vnd.oasis.opendocument.text', MimeTypes::guess('note.odt'));
        $this->assertSame('text/csv', MimeTypes::guess('data.csv'));
        $this->assertSame('application/vnd.rar', MimeTypes::guess('a.rar'));
        $this->assertSame('image/heic', MimeTypes::guess('photo.heic'));
        $this->assertSame('image/heif', MimeTypes::guess('photo.heif'));
        $this->assertSame('image/apng', MimeTypes::guess('anim.apng'));
        $this->assertSame('image/jpeg', MimeTypes::guess('x.jfif'));
        $this->assertSame('image/jpeg', MimeTypes::guess('x.pjpeg'));
        $this->assertSame('image/jpeg', MimeTypes::guess('x.pjp'));
        $this->assertSame('image/jxl', MimeTypes::guess('x.jxl'));
        $this->assertSame('image/svg+xml', MimeTypes::guess('icon.svgz'));
        $this->assertSame('video/ogg', MimeTypes::guess('clip.ogv'));
        $this->assertSame('video/3gpp2', MimeTypes::guess('clip.3g2'));
        $this->assertSame('audio/opus', MimeTypes::guess('clip.opus'));
        $this->assertSame('audio/ogg', MimeTypes::guess('clip.oga'));
        $this->assertSame('audio/webm', MimeTypes::guess('clip.weba'));
        $this->assertSame('audio/aiff', MimeTypes::guess('clip.aiff'));
        $this->assertNull(MimeTypes::guess('app.ts'), 'do not map .ts to MPEG-TS (TypeScript clash)');
    }

    public function testGuessStripsQueryString(): void
    {
        $this->assertSame('image/webp', MimeTypes::guess('https://cdn.example/a.webp?x=1'));
    }

    public function testGuessUnknownOrEmpty(): void
    {
        $this->assertNull(MimeTypes::guess('clip.bin'));
        $this->assertNull(MimeTypes::guess('noext'));
        $this->assertNull(MimeTypes::guess(''));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function remainingExtensionsProvider(): array
    {
        return [
            'bmp' => ['file.bmp', 'image/bmp'],
            'tif' => ['scan.tiff', 'image/tiff'],
            'avif' => ['x.avif', 'image/avif'],
            'avi' => ['clip.avi', 'video/x-msvideo'],
            'mov' => ['clip.mov', 'video/quicktime'],
            'webm' => ['clip.webm', 'video/webm'],
            'mpeg' => ['clip.mpeg', 'video/mpeg'],
            'mpg' => ['clip.mpg', 'video/mpeg'],
            '3gp' => ['clip.3gp', 'video/3gpp'],
            '3gpp' => ['clip.3gpp', 'video/3gpp'],
            '3g2' => ['clip.3g2', 'video/3gpp2'],
            'flv' => ['clip.flv', 'video/x-flv'],
            'm4v' => ['clip.m4v', 'video/x-m4v'],
            'mkv' => ['clip.mkv', 'video/x-matroska'],
            'wmv' => ['clip.wmv', 'video/x-ms-wmv'],
            'wav' => ['clip.wav', 'audio/wav'],
            'm4a' => ['clip.m4a', 'audio/mp4'],
            'aac' => ['clip.aac', 'audio/aac'],
            'flac' => ['clip.flac', 'audio/flac'],
            'mid' => ['clip.mid', 'audio/midi'],
            'midi' => ['clip.midi', 'audio/midi'],
            'aif' => ['clip.aif', 'audio/aiff'],
            'caf' => ['clip.caf', 'audio/x-caf'],
            'wma' => ['clip.wma', 'audio/x-ms-wma'],
            'mpga' => ['clip.mpga', 'audio/mpeg'],
            'pcm' => ['clip.pcm', 'audio/pcm'],
            'dotx' => ['tpl.dotx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.template'],
            'xltx' => ['tpl.xltx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.template'],
            'potx' => ['tpl.potx', 'application/vnd.openxmlformats-officedocument.presentationml.template'],
            'xls' => ['sheet.xls', 'application/vnd.ms-excel'],
            'ppt' => ['deck.ppt', 'application/vnd.ms-powerpoint'],
            'ods' => ['sheet.ods', 'application/vnd.oasis.opendocument.spreadsheet'],
            'odp' => ['deck.odp', 'application/vnd.oasis.opendocument.presentation'],
            'pdf' => ['doc.pdf', 'application/pdf'],
            'rtf' => ['doc.rtf', 'application/rtf'],
            'json' => ['data.json', 'application/json'],
            'txt' => ['note.txt', 'text/plain'],
            'md' => ['note.md', 'text/markdown'],
            'html' => ['page.html', 'text/html'],
            'css' => ['site.css', 'text/css'],
            'js' => ['app.js', 'text/javascript'],
            'mjs' => ['app.mjs', 'text/javascript'],
            'xml' => ['data.xml', 'application/xml'],
            'yaml' => ['cfg.yaml', 'application/yaml'],
            'yml' => ['cfg.yml', 'application/yaml'],
            'zip' => ['bundle.zip', 'application/zip'],
            '7z' => ['bundle.7z', 'application/x-7z-compressed'],
            'gz' => ['bundle.gz', 'application/gzip'],
            'tgz' => ['bundle.tgz', 'application/gzip'],
            'tar' => ['bundle.tar', 'application/x-tar'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('remainingExtensionsProvider')]
    public function testGuessCoversRemainingAliases(string $file, string $mime): void
    {
        $this->assertSame($mime, MimeTypes::guess($file));
    }
}
