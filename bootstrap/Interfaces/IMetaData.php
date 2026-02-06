<?php

interface IMetaData
{
    public function getId(): string;
    public function getKey(): string;
    public function getValue(): object;
}