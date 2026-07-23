<?php

declare(strict_types=1);

namespace Switon\Orm\Attribute;

use Attribute;
use ReflectionClass;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\Entity;
use Switon\Orm\EntityMetadataInterface;
use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;

use function is_int;

/**
 * Validation constraint that enforces uniqueness for a field, optionally within a scope.
 *
 * Road-signs:
 * - repository query checks duplicates
 * - filters can scope uniqueness
 * - current row is excluded on update
 *
 * Guidance: Back this constraint with a matching database index whenever the rule matters for integrity.
 *
 * @see \Switon\Orm\EntityMetadataInterface::getConstraints()
 * @see \Switon\Orm\Attribute\Exists
 * @see \Switon\Orm\Attribute\Immutable
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Unique extends AbstractConstraint
{
    #[Autowired] protected EntityMetadataInterface $entityMetadata;

    /**
     * Initialize the uniqueness constraint.
     *
     * @param array<int|string, mixed> $filters Additional filter conditions for scoped uniqueness.
     *                      - Numeric keys: Use entity property value (e.g., ['company_id'])
     *                      - String keys: Use fixed value (e.g., ['status' => 'active'])
     * @param string|null $label Display label for the current field
     * @param array<string, string> $labels Display labels for related fields
     * @param string|null $message Custom validation error message
     */
    public function __construct(
        public array      $filters = [],
        protected ?string $label = null,
        protected array   $labels = [],
        public ?string    $message = null,
    ) {
        parent::__construct($label, $labels, $message);
    }

    /**
     * Validate that the field value is unique within the specified scope.
     *
     * @param Validation $validation The validation context containing value and source entity
     *
     * @return bool True if validation passes (value is unique), false if validation fails or source is not an Entity
     */
    public function validate(Validation $validation): bool
    {
        $source = $validation->source;

        if (!$source instanceof Entity) {
            return false;
        }

        $filters = [$validation->field => $validation->value];
        foreach ($this->filters as $key => $value) {
            if (is_int($key)) {
                $filters[$value] = $source->$value;
            } else {
                $filters[$key] = $value;
            }
        }

        $primaryKey = $this->entityMetadata->getPrimaryKey($source::class);
        if (isset($source->$primaryKey)) {
            $filters[$primaryKey . '!='] = $source->$primaryKey;
        }

        $exists = $this->entityMetadata->getRepository($source::class)->exists($filters);
        if ($exists) {
            // Provide richer placeholders for message templates.
            // Note: Validation::addError() always adds {field}.
            $entity = (new ReflectionClass($source::class))->getShortName();
            $fields = array_keys($filters);
            // Remove primary-key exclusion marker from "fields" hint.
            $fields = array_values(array_filter($fields, static fn ($f) => $f !== $primaryKey . '!='));

            return $validation->addError($this->getMessage(), [
                'entity' => $entity,
                'fields' => $fields,
            ]);
        }

        return true;
    }
}
