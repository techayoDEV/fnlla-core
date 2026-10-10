<?php

declare(strict_types=1);

return [
    // These application-owned tables must be created by a reviewed migration
    // before an ActionRunner mutation is enabled.
    "receipts_table" => env("ACTION_RECEIPTS_TABLE", "fnlla_action_receipts"),
    "outbox_table" => env("ACTION_OUTBOX_TABLE", "fnlla_action_outbox"),
    "delivery_table" => env("ACTION_OUTBOX_DELIVERY_TABLE", "fnlla_outbox_deliveries"),
    // Enable after the application migration installs the delivery side table.
    "reliable_outbox" => (bool) env("ACTION_RELIABLE_OUTBOX", true),
    "publish_after_commit" => (bool) env("ACTION_PUBLISH_AFTER_COMMIT", false),
    "outbox" => ["max_attempts" => 5, "lease_seconds" => 60, "retry_seconds" => 5],
];
