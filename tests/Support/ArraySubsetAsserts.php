<?php

namespace Mpociot\ApiDoc\Tests\Support;

trait ArraySubsetAsserts
{
    protected function assertArraySubset(array $expectedSubset, array $actualArray, string $prefix = ''): void
    {
        foreach ($expectedSubset as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $this->assertArrayHasKey($key, $actualArray, "Missing key '{$path}'.");

            if (is_array($value)) {
                $this->assertIsArray($actualArray[$key], "Value at '{$path}' is not array.");
                $this->assertArraySubset($value, $actualArray[$key], $path);
                continue;
            }

            $this->assertSame($value, $actualArray[$key], "Mismatch at '{$path}'.");
        }
    }
}
