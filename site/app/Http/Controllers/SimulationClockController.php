<?php
/** Office demo controls advance only the shared business date, never tasks or real audit timestamps. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\BusinessClock;

final class SimulationClockController extends Controller
{
    public function show(): array
    {
        return ['clock' => BusinessClock::state()];
    }

    public function skip(): array
    {
        self::requireDemo();
        return ['clock' => BusinessClock::skip()];
    }

    public function reset(): array
    {
        self::requireDemo();
        return ['clock' => BusinessClock::reset()];
    }

    private static function requireDemo(): void
    {
        if (!config('laundry.simulator') || app()->isProduction()) {
            fail(404, 'not_found', 'The demo business clock is switched off.');
        }
        if (!BusinessClock::enabled()) {
            fail(409, 'setup_required', 'Apply the delivery-date migration before using the demo clock.');
        }
    }
}
