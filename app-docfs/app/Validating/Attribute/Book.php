<?php

declare(strict_types=1);

namespace App\Validating\Attribute;

use App\Docfs\CredentialInterface;
use Attribute;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Strings;
use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;

use function is_string;

/**
 * Book slug (+ optional ACL / existence) for docfs MCP / typed input.
 *
 * Always checks {@see Strings::isSlug()}. {@see $exists}: `true` must exist (default),
 * `false` must not, `null` skips FS check. With {@see $perm}, reads JWT `books`;
 * missing `books` skips permission. When {@see $exists} is `false`, write requires
 * wildcard `*` grant. Instantiate via {@see \Switon\Core\MakerInterface::make()}.
 *
 * @see \Switon\Validating\AbstractConstraint
 * @see \App\Docfs\CredentialInterface
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Book extends AbstractConstraint
{
    #[Autowired] protected CredentialInterface $credential;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * @param string|null $perm Required grant when JWT `books` is present
     * @param bool|null $exists `true` must exist (default); `false` must not; `null` skip
     * @param string|null $label Display label for the current field
     * @param array<string, string> $labels Display labels for related fields
     * @param string|null $message Override for the invalid-slug message only
     */
    public function __construct(
        public readonly ?string $perm = null,
        public readonly ?bool $exists = true,
        ?string $label = null,
        array $labels = [],
        ?string $message = null,
    ) {
        parent::__construct($label, $labels, $message);
    }

    public function validate(Validation $validation): bool
    {
        if (!is_string($validation->value) || !Strings::isSlug($validation->value)) {
            return $validation->addError(
                $this->message ?? 'Invalid book',
                [],
                $this->getLabels($validation->field),
            );
        }

        $dir = '@books/' . $validation->value;

        if ($this->exists === true && !$this->filesystem->isDir($dir)) {
            return $validation->addError('Book does not exist', [], $this->getLabels($validation->field));
        }

        if ($this->exists === false && $this->filesystem->isDir($dir)) {
            return $validation->addError('already exists', [], $this->getLabels($validation->field));
        }

        if ($this->perm === null) {
            return true;
        }

        $books = $this->credential->payload()['books'] ?? null;
        if ($books === null) {
            return true;
        }

        if ($this->exists === false) {
            if (($books['*'] ?? null) !== CredentialInterface::PERM_WRITE) {
                return $validation->addError('forbidden', [], $this->getLabels($validation->field));
            }

            return true;
        }

        $granted = $books[$validation->value] ?? $books['*'] ?? null;

        if ($this->perm === CredentialInterface::PERM_WRITE) {
            if ($granted !== CredentialInterface::PERM_WRITE) {
                return $validation->addError('Book not writable', [], $this->getLabels($validation->field));
            }

            return true;
        }

        if ($granted === null) {
            return $validation->addError('Book not allowed', [], $this->getLabels($validation->field));
        }

        return true;
    }
}
