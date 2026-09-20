<?php

declare(strict_types=1);

return [
    // These application-owned tables must be created by a reviewed migration
    // before an ActionRunner mutation is enabled.
    "receipts_table" => env("ACTION_RECEIPTS_TABLE", "fnlla_action_receipts"),
    "outbox_table" => env("ACTION_OUTBOX_TABLE", "fnlla_action_outbox"),
];
