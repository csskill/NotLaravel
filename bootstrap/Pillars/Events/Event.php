<?php

namespace Nraa\Pillars\Events;

abstract class Event
{
    /**
     * Generate a stable, safe event identity hash.
     *
     * This is used to prevent duplicate dispatching
     * within the same process.
     */
    final public function eventId(): string
    {
        if ($override = $this->overrideEventId()) {
            return md5(static::class . ':' . $override);
        }
    
        return md5(static::class . ':' . $this->payloadFingerprint());
    }

    /**
     * Build a fingerprint from public scalar properties.
     *
     * Objects, resources, and services are ignored automatically.
     */
    protected function payloadFingerprint(): string
    {
        $data = [];

        $reflection = new \ReflectionObject($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($this);

            if ($this->isSerializableValue($value)) {
                $data[$property->getName()] = $value;
            }
        }

        ksort($data);

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Determine if a value is safe to include in an event fingerprint.
     */
    protected function isSerializableValue(mixed $value): bool
    {
        return is_null($value)
            || is_scalar($value)
            || (is_object($value) && method_exists($value, '__toString'));
    }

    /**
     * Optional override for edge cases.
     * Rarely needed.
     */
    protected function overrideEventId(): ?string
    {
        return null;
    }
}