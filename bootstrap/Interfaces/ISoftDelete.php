<?php
declare(strict_types=1);

interface ISoftDelete
{
    public function getCreatedAt(): ?\DateTime;
    public function getDeletedAt(): ?\DateTime;
}