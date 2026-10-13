<?php
/** The lookup that changes nothing, and the list of mats the simulator can "hear". */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;

final class ScanController extends Controller
{
    public function lookup(Request $request): array
    {
        Scan::source($request->all());
        $epcs = Scan::reads($request->all());
        $items = Scan::itemsByEpc($epcs);
        $results = [];
        foreach ($epcs as $epc) {
            $results[] = isset($items[$epc]) ? Scan::result($epc, 'found', $items[$epc]) : Scan::result($epc, 'unknown', null);
        }
        return ['results' => $results, 'missing' => []];
    }

    /** Simulator only: a real scanner hears any mat near it, so the pile panel must be able to pick any mat. */
    public function simItems(): array
    {
        if (!config('laundry.simulator')) {
            fail(404, 'not_found', 'No such path.');
        }
        return ['items' => rows('SELECT sku, epc FROM items ORDER BY sku')];
    }
}
