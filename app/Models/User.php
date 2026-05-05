<?php

namespace Nraa\Models;

use Nraa\Database\Model;
use Nraa\Database\Attributes\Index;


#[Index(keys: ['user_id' => 1, 'created_at' => -1])]
#[Index(keys: ['user_id' => 1, 'read_at' => 1])]
#[Index(keys: ['type' => 1])]
class User extends Model
{
    use \Nraa\Database\Traits\HasRelations;

    protected static $collection = 'users';

    // User reference
    public string $id;
    public string $username = '';

    public function __construct()
    {
        parent::__construct();
    }
}
