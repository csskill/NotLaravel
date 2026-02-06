<?php

namespace Nraa\Database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class BelongsToMany
{
    public function __construct(
        public string $className,
        public string $foreignKey,
        public string $primaryKey,
        public ?string $pivotTable = null,
        public ?string $pivotForeignKey = null,
        public ?string $pivotRelatedKey = null,
        public string $onDelete = 'restrict' // 'cascade' | 'setNull' | 'restrict'
    ) {}
}
