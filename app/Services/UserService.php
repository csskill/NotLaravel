<?php

namespace Nraa\Services;

use Nraa\Models\Users\User;
use MongoDB\BSON\ObjectId;

class UserService
{
    /*
    * Get user by id
    * @param string $id The id of the user
    * @return User | null The user or null if not found
    */
    public function getUserById(string $id): User | null
    {
        return User::findOne(['_id' => new ObjectId($id)]);
    }

    /*
    * Get all users with Steam auth enabled
    * @return array The users
    */
    public function getAllUsersWithSteamAuth(): array
    {
        return User::find([
            'steam_id' => ['$exists' => true, '$ne' => null],
            'steam_auth_code' => ['$exists' => true, '$ne' => '']
        ])->toArray();
    }

    /**
     * Get all users
     * @return array The users
     */
    public function getAllUsers(): array
    {
        return User::find([])->toArray();
    }

    /**
     * Get all administrators
     * @return array The administrators
     */
    public function getAllAdministrators(): array
    {
        return User::find(['roles' => 'administrator'])->toArray();
    }

    /**
     * Get all moderators
     * @return array The moderators
     */
    public function getAllModerators(): array
    {
        return User::find(['roles' => 'moderator'])->toArray();
    }

    /*
    * Get user by steam id
    * @param string $steamId The steam id of the user
    * @return User | null The user or null if not found
    */
    public function getUserBySteamId(string $steamId): User | null
    {
        return User::findOne(['steam_id' => $steamId]);
    }
}
