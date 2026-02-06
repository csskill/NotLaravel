
<?php   

namespace Nraa\Database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class NoBind
{
    public function __construct() {}
}
