<?php

declare(strict_types=1);

/*
===============================================================================
PROJECT DATABASE SEEDER
File: database\seeders\DatabaseSeeder.php
Purpose:
- Keeps the exported project ready for project-specific seed data without shipping demo users by default.
===============================================================================
*/

namespace Database\Seeders;

use Fnlla\Php\Database\Seeders\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Add project-specific seed data here when the application needs it.
    }
}
