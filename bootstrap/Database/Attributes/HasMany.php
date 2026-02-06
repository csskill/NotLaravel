<?php

namespace Nraa\Database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class HasMany
{
    public function __construct(
        public string $className,
        public string $foreignKey,
        public string $primaryKey,
        public string $onDelete = 'restrict' // 'cascade' | 'setNull' | 'restrict'
    ) {}
}
