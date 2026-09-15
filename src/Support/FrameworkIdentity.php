<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\FrameworkIdentity.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Provides one canonical framework identity source for URLs, support contacts,
  release metadata and downstream exports.
*/

namespace Fnlla\Php\Support;

final class FrameworkIdentity
{
    public const PRODUCT_NAME = "FNLLA Core";
    public const PRODUCT_SLUG = "fnlla-core";
    public const OFFICIAL_DOMAIN = "fnlla.com";
    public const OFFICIAL_URL = "https://fnlla.com";
    public const SUPPORT_EMAIL = "support@fnlla.com";
    public const MAIL_FROM_ADDRESS = "noreply@fnlla.com";
    public const MAINTAINER_LEGAL = "TechAyo LTD";
    public const MAINTAINER_NAME = "TechAyo";
    public const LEAD_NAME = "Marcin Kordyaczny";
    public const LEAD_ROLE = "Lead Developer / Product Manager";
    public const MAINTAINER_URL = "https://techayo.co.uk";
    public const ORIGIN = "Finella Gardens in Dundee, UK";
    public const REPOSITORY = "techayoDEV/fnlla-core";
    public const REPOSITORY_URL = "https://github.com/techayoDEV/fnlla-core.git";
    public const REPOSITORY_WEB_URL = "https://github.com/techayoDEV/fnlla-core";
    public const GITHUB_API_BASE_URL = "https://api.github.com";
    public const DEFAULT_RUNTIME_CREATOR = "TechAyo LTD (techayo.co.uk)";
}
