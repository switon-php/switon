<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validating\Attribute;

use App\Docfs\CredentialInterface;
use App\Validating\Attribute\Book;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;
use Switon\Validating\Exception\ValidateFailedException;
use Switon\Validating\ValidatorInterface;

#[CoversClass(Book::class)]
final class BookTest extends TestCase
{
    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_book_' . bin2hex(random_bytes(8));
        mkdir($this->booksRoot, 0775, true);
        $this->container->get(PathAliasInterface::class)->set('@books', $this->booksRoot);

        $this->credential = new class () implements CredentialInterface {
            /** @var array<string, mixed> */
            public array $payload = [
                'books' => ['demo' => CredentialInterface::PERM_WRITE],
            ];

            public function payload(): array
            {
                return $this->payload;
            }
        };
        $this->container->set(CredentialInterface::class, $this->credential);
    }

    protected function tearDown(): void
    {
        if (isset($this->booksRoot) && is_dir($this->booksRoot)) {
            $this->removeDirectory($this->booksRoot);
        }
        parent::tearDown();
    }

    public function testSlugOnlyWithExistsNullSkipsFsAndAcl(): void
    {
        $this->assertNull($this->validate('demo', null, null));
    }

    public function testRejectsInvalidSlug(): void
    {
        $this->assertSame('Invalid book', $this->validate('bad/name', null, null));
    }

    public function testExistsDefaultRequiresDir(): void
    {
        $this->assertSame('Book does not exist', $this->validate('demo', CredentialInterface::PERM_READ));
    }

    public function testExistsTruePassesWhenDirPresent(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        $this->assertNull($this->validate('demo', CredentialInterface::PERM_READ));
    }

    public function testExistsFalseRejectsWhenDirPresent(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        $this->credential->payload['books'] = ['*' => CredentialInterface::PERM_WRITE];
        $this->assertSame('already exists', $this->validate('demo', CredentialInterface::PERM_WRITE, false));
    }

    public function testExistsFalseRequiresWildcardWrite(): void
    {
        $this->credential->payload['books'] = ['demo' => CredentialInterface::PERM_WRITE];
        $this->assertSame('forbidden', $this->validate('demo', CredentialInterface::PERM_WRITE, false));
    }

    public function testReadRejectsMissingGrant(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        $this->credential->payload['books'] = [];
        $this->assertSame('Book not allowed', $this->validate('demo', CredentialInterface::PERM_READ));
    }

    public function testWriteRequiresWriteGrant(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        $this->credential->payload['books'] = ['demo' => CredentialInterface::PERM_READ];
        $this->assertSame('Book not writable', $this->validate('demo', CredentialInterface::PERM_WRITE));
    }

    public function testSkipsPermWhenBooksMissing(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        unset($this->credential->payload['books']);
        $this->assertNull($this->validate('demo', CredentialInterface::PERM_WRITE));
    }

    private function validate(string $book, ?string $perm = null, ?bool $exists = true): ?string
    {
        try {
            $this->container->get(ValidatorInterface::class)->validate(['book' => $book], ['book' => [
                $this->make(Book::class, ['perm' => $perm, 'exists' => $exists]),
            ]]);

            return null;
        } catch (ValidateFailedException $e) {
            return $e->getErrors()['book'] ?? $e->getMessage();
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
