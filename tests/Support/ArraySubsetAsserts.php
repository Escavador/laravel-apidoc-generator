<?php

namespace Mpociot\ApiDoc\Tests\Support;

use PHPUnit\Framework\Assert;

trait ArraySubsetAsserts
{
    protected function assertArraySubset(array $subset, array $array, string $path = ''): void
    {
        foreach ($subset as $key => $expectedValue) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;

            Assert::assertArrayHasKey(
                $key,
                $array,
                sprintf("Failed asserting that key '%s' exists.", $currentPath)
            );

            $actualValue = $array[$key];

            if (is_array($expectedValue)) {
                Assert::assertIsArray(
                    $actualValue,
                    sprintf("Failed asserting that value at '%s' is an array.", $currentPath)
                );

                $this->assertArraySubset($expectedValue, $actualValue, $currentPath);
                continue;
            }

            Assert::assertSame(
                $expectedValue,
                $actualValue,
                sprintf("Failed asserting that value at '%s' matches.", $currentPath)
            );
        }
    }
}
