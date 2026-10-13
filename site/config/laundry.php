<?php
/** Settings of the mat tracking app itself. The values come from .env, so the code is the same on every machine. */

return [
    // true while the handheld is simulated. false refuses scans with source "simulator" and hides GET /sim/items.
    'simulator' => (bool) env('SCANNER_SIMULATOR', false),
];
