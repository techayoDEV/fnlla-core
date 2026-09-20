<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

/**
 * Process-local identity used by neutral Core metadata writers.
 *
 * Core supplies the safe default. A consuming product may configure its own
 * identity during bootstrap without replacing a Core class or copying Core
 * source into the consumer repository.
 */
final class RuntimeIdentity
{
    /** @var array<string, string> */
    private static array $values = [
        "name" => FrameworkIdentity::PRODUCT_NAME,
        "slug" => FrameworkIdentity::PRODUCT_SLUG,
        "repository_url" => FrameworkIdentity::REPOSITORY_URL,
        "website" => FrameworkIdentity::OFFICIAL_URL,
        "support" => FrameworkIdentity::SUPPORT_EMAIL,
    ];

    /** @param array<string, string> $values */
    public static function configure(array $values): void
    {
        foreach (array_keys(self::$values) as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = trim($values[$key]);
            if ($value === "") {
                throw new \InvalidArgumentException("Runtime identity value cannot be empty: " . $key);
            }

            self::$values[$key] = $value;
        }
    }

    public static function reset(): void
    {
        self::$values = [
            "name" => FrameworkIdentity::PRODUCT_NAME,
            "slug" => FrameworkIdentity::PRODUCT_SLUG,
            "repository_url" => FrameworkIdentity::REPOSITORY_URL,
            "website" => FrameworkIdentity::OFFICIAL_URL,
            "support" => FrameworkIdentity::SUPPORT_EMAIL,
        ];
    }

    public static function get(string $key): string
    {
        if (!array_key_exists($key, self::$values)) {
            throw new \InvalidArgumentException("Unknown runtime identity key: " . $key);
        }

        return self::$values[$key];
    }
}
