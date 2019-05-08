<?php

namespace DDTrace\Tests\Integrations\CLI;

/**
 * Serialize an associative array into INI string for CLI SAPI
 */
final class IniSerializer
{
    private $inis;

    /**
     * @param array $inis
     */
    public function __construct(array $inis)
    {
        $this->inis = $inis;
    }

    public function __toString()
    {
        $inis = [];
        foreach ($this->inis as $name => $value) {
            $inis[] = '-d' . $name . '=' . escapeshellarg($value);
        }
        return implode(' ', $inis);
    }
}
