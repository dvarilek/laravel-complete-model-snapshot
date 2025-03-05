<?php

declare(strict_types=1);

namespace Dvarilek\CompleteModelSnapshot\DTO;

use Dvarilek\CompleteModelSnapshot\DTO\Contracts\VirtualAttribute;

final readonly class RelatedAttributeTransferObject implements VirtualAttribute
{
    public function __construct(
        public string $attribute,
        public mixed $value,
        public ?string $cast,
        /**
         * @var list<string>
         */
        public array $relationPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'value' => $this->value,
            'cast' => $this->cast,
            'relationPath' => $this->relationPath,
        ];
    }
}