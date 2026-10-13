<?php
/** Mats: registering, releasing, the quality check, office corrections, the mat list and the mat history. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ItemController extends Controller
{
    /** Registers a new mat with its EPC and its two tags. */
    public function store(Request $request): array
    {
        $source = Scan::source($request->all());
        $epc = Scan::hex($request->input('epc'), 'epc');
        $tidPrimary = Scan::hex($request->input('tid_primary'), 'tid_primary');
        $tidSecondary = Scan::hex($request->input('tid_secondary'), 'tid_secondary');
        $in = $request->validate(['sku' => 'required|string|max:64', 'item_type_id' => 'required|integer|min:0']);
        $sku = $in['sku'];
        $typeId = (int) $in['item_type_id'];
        $userId = $request->user()->id;

        if ($tidPrimary === $tidSecondary) {
            fail(422, 'same_tid', 'The two tags of a mat must have different TIDs.');
        }
        if (DB::scalar('SELECT 1 FROM item_types WHERE id = ? AND is_active = 1', [$typeId]) === null) {
            fail(422, 'invalid_reference', 'Unknown mat type.');
        }

        return DB::transaction(function () use ($source, $epc, $tidPrimary, $tidSecondary, $sku, $typeId, $userId): array {
            // Also catches a TID that is primary on one mat and secondary on another, which the unique keys cannot.
            $clash = row('SELECT sku FROM items WHERE epc = ? OR sku = ? OR tid_primary IN (?, ?) OR tid_secondary IN (?, ?) LIMIT 1',
                [$epc, $sku, $tidPrimary, $tidSecondary, $tidPrimary, $tidSecondary]);
            if ($clash !== null) {
                fail(409, 'duplicate', "The EPC, the SKU or a TID is already in use on {$clash['sku']}.");
            }
            DB::insert('INSERT INTO items (item_type_id, sku, epc, tid_primary, tid_secondary) VALUES (?, ?, ?, ?, ?)',
                [$typeId, $sku, $epc, $tidPrimary, $tidSecondary]);
            $item = Scan::itemById((int) DB::getPdo()->lastInsertId());
            Scan::log(['status' => null] + $item, $userId, 'register', 'registered', $source);
            return ['item' => Scan::view($item)];
        });
    }
}
