<?php

namespace MonkeysLegion\Files\Contracts;

interface FileNamer
{
    /** Produce a relative storage path without leading slash. */
    public function path(string $originalName, string $mime, string $sha256): string;
}
