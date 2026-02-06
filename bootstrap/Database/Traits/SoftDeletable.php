<?php

namespace Nraa\Database\Traits;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

trait SoftDeletable
{
    public bool $deleted = false;
    public ?\MongoDB\BSON\UTCDateTime $deletedAt = null;

    protected bool $includeDeleted = false;
    protected bool $force = false;

    /**
     * Override the delete method to implement soft delete functionality.
     */
    public function forceDelete(): void
    {
        // mark that we want to bypass soft-delete and perform a hard delete
        $this->force = true;
        $this->delete();
        // reset flag to avoid surprising side effects
        $this->force = false;
    }

    /**
     * Override the delete method to implement soft delete functionality.
     */
    public function restore(): void
    {
        $this->deleted = false;
        $this->deletedAt = null;
        $this->save();
    }

    /**
     * Override the delete method to implement soft delete functionality.
     */
    public function trashed(): self
    {
        // include deleted records for the next retrieval
        $this->includeDeleted = true;
        return $this;
    }
}
