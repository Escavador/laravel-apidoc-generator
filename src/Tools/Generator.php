<?php

namespace Mpociot\ApiDoc\Tools;

class Generator
{
    /**
     * Backward-compatible helper used by older published Blade templates.
     */
    public static function printArray(array $value): string
    {
        return static::exportValue($value);
    }

    /**
     * Export PHP values as inline code snippets for example requests.
     *
     * @param mixed $value
     */
    private static function exportValue($value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $isSequential = array_keys($value) === range(0, count($value) - 1);
            $parts = [];

            foreach ($value as $key => $item) {
                $itemExport = static::exportValue($item);
                $parts[] = $isSequential
                    ? $itemExport
                    : static::exportValue($key) . ' => ' . $itemExport;
            }

            return '[' . implode(', ', $parts) . ']';
        }

        if (is_string($value)) {
            return "'" . str_replace("'", "\\\\'", $value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }
}
