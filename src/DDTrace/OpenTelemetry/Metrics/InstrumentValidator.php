<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics;

/**
 * Utility class for validating instrument configurations per OpenTelemetry spec
 */
final class InstrumentValidator
{
    /**
     * Validate instrument name according to OpenTelemetry specification
     * 
     * Requirements:
     * - Not null and not empty
     * - Case-sensitive ASCII string
     * - First character must be alphabetic
     * - Subsequent characters must be alphanumeric, '_', '.', '/', or '-'
     * - Maximum length of 255 characters
     * 
     * @param string|null $name The instrument name to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidInstrumentName(?string $name): bool
    {
        // Check null and empty
        if ($name === null || $name === '') {
            return false;
        }
        
        // Check maximum length
        if (strlen($name) > 255) {
            return false;
        }
        
        // Check ASCII and first character is alphabetic
        if (!preg_match('/^[a-zA-Z]/', $name)) {
            return false;
        }
        
        // Check all characters are valid (alphanumeric, '_', '.', '/', '-')
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.\\/\\-]*$/', $name)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate advisory parameter for explicit bucket boundaries
     * 
     * @param array<float>|null $boundaries
     * @return bool
     */
    public static function isValidExplicitBucketBoundaries(?array $boundaries): bool
    {
        if ($boundaries === null) {
            return true;
        }
        
        if (empty($boundaries)) {
            return false;
        }
        
        // Check all values are numeric
        foreach ($boundaries as $boundary) {
            if (!is_numeric($boundary)) {
                return false;
            }
        }
        
        // Check boundaries are in ascending order
        $sorted = $boundaries;
        sort($sorted, SORT_NUMERIC);
        
        return $sorted === $boundaries;
    }
    
    /**
     * Normalize unit parameter (null or empty string should be treated as empty string)
     * 
     * @param string|null $unit
     * @return string
     */
    public static function normalizeUnit(?string $unit): string
    {
        return $unit ?? '';
    }
    
    /**
     * Normalize description parameter (null should be treated as empty string)
     * 
     * @param string|null $description
     * @return string
     */
    public static function normalizeDescription(?string $description): string
    {
        return $description ?? '';
    }
    
    /**
     * Log a warning about invalid instrument name
     * 
     * @param string|null $name
     * @return void
     */
    public static function logInvalidNameWarning(?string $name): void
    {
        $message = sprintf(
            'Invalid instrument name: "%s". Name must be a non-empty ASCII string, ' .
            'starting with an alphabetic character, containing only alphanumeric characters, ' .
            '"_", ".", "/", "-", and having a maximum length of 255 characters.',
            $name ?? 'null'
        );
        
        error_log("[DDTrace] OpenTelemetry Metrics: $message");
    }
    
    /**
     * Log a warning about invalid advisory parameter
     * 
     * @param string $parameterName
     * @return void
     */
    public static function logInvalidAdvisoryParameterWarning(string $parameterName): void
    {
        $message = sprintf(
            'Invalid advisory parameter "%s". The parameter will be ignored.',
            $parameterName
        );
        
        error_log("[DDTrace] OpenTelemetry Metrics: $message");
    }
    
    /**
     * Log a warning about conflicting instrument registration
     * 
     * @param string $name
     * @param string $conflictType
     * @return void
     */
    public static function logInstrumentConflictWarning(string $name, string $conflictType): void
    {
        $message = sprintf(
            'Instrument "%s" already registered with different %s. ' .
            'Returning existing instrument. Previous advisory parameters will be used.',
            $name,
            $conflictType
        );
        
        error_log("[DDTrace] OpenTelemetry Metrics: $message");
    }
}

