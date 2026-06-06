<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Attachment;

class AttachmentTest extends TestCase
{
    public function testGetters(): void
    {
        $att = new Attachment('doc.pdf', 'application/pdf', 'binary');
        $this->assertSame('doc.pdf', $att->getFilename());
        $this->assertSame('application/pdf', $att->getMediaType());
        $this->assertSame('binary', $att->getContent());
    }
}
