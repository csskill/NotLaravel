<?php
/**
 * MongoDB BSON stubs for static analysis / IDEs.
 *
 * Some environments (or language servers) may not load the MongoDB extension
 * stubs, which causes false-positive “Undefined type” diagnostics.
 *
 * This file is intentionally NOT autoloaded; it only exists for tooling.
 */

namespace MongoDB\BSON;

if (!class_exists('MongoDB\\BSON\\ObjectId')) {
    class ObjectId
    {
        public function __construct($id = null) {}
        public function __toString(): string { return ''; }
    }
}

if (!class_exists('MongoDB\\BSON\\UTCDateTime')) {
    class UTCDateTime
    {
        public function __construct($milliseconds = null) {}
        public function toDateTime(): \DateTime { return new \DateTime(); }
    }
}

