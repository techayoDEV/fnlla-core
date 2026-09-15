<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\PageMeta.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Supports framework maintenance, validation, release hygiene or repository hardening.
*/

namespace Fnlla\Php\Support;

final class PageMeta
{
    public static function resolve(array $input, string $defaultSite): array
    {
        $meta = [
            "site" => self::normalize((string) ($input["site"] ?? $defaultSite)),
            "page" => self::normalize((string) ($input["page"] ?? "")),
            "section" => self::normalize((string) ($input["section"] ?? "")),
            "suffix" => self::normalize((string) ($input["suffix"] ?? "")),
            "tagline" => self::normalize((string) ($input["tagline"] ?? "")),
            "home" => (bool) ($input["home"] ?? false),
        ];

        $meta["title"] = self::composeDocumentTitle($meta);

        return $meta;
    }

    public static function composeDocumentTitle(array $input): string
    {
        $site = self::normalize((string) ($input["site"] ?? ""));
        $page = self::normalize((string) ($input["page"] ?? ""));
        $section = self::normalize((string) ($input["section"] ?? ""));
        $suffix = self::normalize((string) ($input["suffix"] ?? ""));
        $tagline = self::normalize((string) ($input["tagline"] ?? ""));
        $home = (bool) ($input["home"] ?? false);
        $parts = [];

        if ($home && $site !== "") {
            $parts[] = $site;
        } else {
            $parts[] = $page;
            $parts[] = $section;
            $parts[] = $site;
        }

        if ($suffix !== "") {
            $parts[] = $suffix;
        }

        $title = implode(" | ", self::deduplicateParts($parts));

        if ($tagline !== "" && strcasecmp($tagline, $title) !== 0) {
            return $title !== "" ? $title . " - " . $tagline : $tagline;
        }

        return $title;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private static function deduplicateParts(array $parts): array
    {
        $unique = [];
        $seen = [];

        foreach ($parts as $part) {
            $normalized = self::normalize($part);

            if ($normalized === "") {
                continue;
            }

            $key = strtolower($normalized);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $normalized;
        }

        return $unique;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
