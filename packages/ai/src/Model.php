<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/**
 * Catalog record for one route.
 *
 * `$id` is `provider/modelKey`. `$model` is the wire name.
 */
class Model implements JsonSerializable
{
    /**
     * @param list<string>|null $efforts
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $id = '',
        public string $model = '',
        public int $window = 0,
        public bool $image = false,
        public bool $video = false,
        public ?array $efforts = null,
        public array $extra = [],
        public string $description = '',
    ) {
    }

    /**
     * @param list<string>|null $efforts
     * @param array<string, mixed> $extra
     */
    public static function of(
        string $id = '',
        string $model = '',
        int $window = 0,
        bool $image = false,
        bool $video = false,
        ?array $efforts = null,
        array $extra = [],
        string $description = '',
    ): static {
        return new static($id, $model, $window, $image, $video, $efforts, $extra, $description);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $model = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($model, $key)) {
                continue;
            }
            $model->$key = $value;
        }

        return $model;
    }
}
