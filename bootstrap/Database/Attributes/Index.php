<?php

namespace Nraa\Database\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Index
{
    public function __construct(
        public array $keys,          // e.g. ['email' => 1] or ['createdAt' => -1]
        public array $options = []   // e.g. ['unique' => true, 'sparse' => true]
    ) {}
}
