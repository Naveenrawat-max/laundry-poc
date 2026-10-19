<?php
/** Areas, categories, customers, buildings, zones and mat types share office reference management. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RefdataController extends Controller
{
    // Per resource: its table, the column that points to its parent (or null), and the rules of its fields.
    // Table and column names come only from this constant, never from a request.
    // "sometimes|required" means: may be left out, but when it is sent it must have a value.
    private const RESOURCES = [
        'areas' => ['table' => 'areas', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:80',
        ]],
        'categories' => ['table' => 'categories', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'customers' => ['table' => 'customers', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:160',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|string|max:190',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'item-types' => ['table' => 'item_types', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:120',
            'category_id' => 'required|integer|min:1',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'buildings' => ['table' => 'buildings', 'parent' => 'customer_id', 'rules' => [
            'area_id' => 'nullable|integer|min:1',
            'name' => 'required|string|max:160',
            'street' => 'required|string|max:160',
            'postcode' => 'required|string|max:12',
            'city' => 'required|string|max:80',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'zones' => ['table' => 'zones', 'parent' => 'building_id', 'rules' => [
            'name' => 'required|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]],
    ];

    /** The resource a route is for. Each route names it itself, see routes/api.php. */
    private function resource(Request $request): array
    {
        return self::RESOURCES[$request->route('resource')];
    }

    private function view(object $row): array
    {
        $row = (array) $row;
        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (bool) $row['is_active'];
        }
        return $row;
    }

    private function find(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            fail(404, 'not_found', 'No such record.');
        }
        return $this->view($row);
    }

    public function index(Request $request): array
    {
        ['table' => $table, 'parent' => $parent] = $this->resource($request);
        $rows = DB::table($table)
            ->when($parent !== null, fn ($query) => $query->where($parent, (int) $request->route('id')))
            ->orderBy('name')
            ->get();
        return ['rows' => $rows->map(fn (object $row) => $this->view($row))->all()];
    }

    public function store(Request $request): array
    {
        ['table' => $table, 'parent' => $parent, 'rules' => $rules] = $this->resource($request);
        if ($table === 'customers') {
            $rules += ['locations' => 'sometimes|required|array|list|min:1|max:100',
                'locations.*' => 'required|array:name,street,postcode,city'];
            foreach (['name', 'street', 'postcode', 'city'] as $field) {
                $rules['locations.*.'.$field] = self::RESOURCES['buildings']['rules'][$field];
            }
        }
        $values = $request->validate($rules);
        $this->validateCategory($table, $values);
        $this->uniqueName($table, $values);
        if ($parent !== null) {
            $values[$parent] = (int) $request->route('id');
        }
        return DB::transaction(function () use ($table, $values): array {
            $locations = $values['locations'] ?? [];
            unset($values['locations']);
            $id = $table === 'buildings' ? $this->insertLocation($values) : DB::table($table)->insertGetId($values);
            foreach ($locations as $location) {
                $this->insertLocation($location + ['customer_id' => $id]);
            }
            $result = ['row' => $this->find($table, $id)];
            if ($locations !== []) {
                $result['locations'] = DB::table('buildings')->where('customer_id', $id)->orderBy('id')->get()
                    ->map(fn (object $location) => $this->view($location))->all();
            }
            return $result;
        });
    }

    /** Every new customer location has one usable zone without another setup step. */
    private function insertLocation(array $values): int
    {
        if (DB::scalar('SELECT 1 FROM customers WHERE id = ?', [$values['customer_id']]) === null) {
            fail(422, 'invalid_reference', 'Choose an existing customer for this location.');
        }
        $id = DB::table('buildings')->insertGetId($values);
        DB::table('zones')->insert(['building_id' => $id, 'name' => 'Main area']);
        return $id;
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request): array
    {
        ['table' => $table, 'rules' => $rules] = $this->resource($request);
        $id = (int) $request->route('id');
        $this->find($table, $id);
        $values = $request->validate(array_map(fn (string $rule) => "sometimes|$rule", $rules));
        $this->validateCategory($table, $values);
        $this->uniqueName($table, $values + $this->find($table, $id), $id);
        if ($values !== []) {
            DB::table($table)->where('id', $id)->update($values);
        }
        return ['row' => $this->find($table, $id)];
    }

    private function validateCategory(string $table, array $values): void
    {
        if ($table === 'item_types' && isset($values['category_id'])
            && DB::scalar('SELECT 1 FROM categories WHERE id = ? AND is_active = 1', [$values['category_id']]) === null) {
            fail(422, 'invalid_reference', 'Choose an active category.');
        }
    }

    private function uniqueName(string $table, array $values, ?int $id = null): void
    {
        if (!in_array($table, ['categories', 'item_types'], true)) {
            return;
        }
        $query = DB::table($table)->where('name', $values['name'])->when($id !== null, fn ($q) => $q->where('id', '!=', $id));
        if ($table === 'item_types') {
            $query->where('category_id', $values['category_id']);
        }
        if ($query->exists()) {
            fail(409, 'duplicate', 'This name already exists in this category.');
        }
    }

    /** Removes one setup row, or names the single next thing that still uses it. */
    public function destroy(Request $request): array
    {
        ['table' => $table] = $this->resource($request);
        $id = (int) $request->route('id');
        $row = $this->find($table, $id);
        DB::transaction(function () use ($table, $id, $row): void {
            match ($table) {
                'categories' => $this->deleteCategory($id),
                'item_types' => $this->deleteType($id, (string) $row['name']),
                'zones' => $this->deleteZone($id),
                'buildings' => $this->deleteBuilding($id),
                'customers' => $this->deleteCustomer($id, (string) $row['name']),
                'areas' => $this->deleteArea($id),
                default => fail(404, 'not_found', 'No such record.'),
            };
        });
        return ['deleted' => true];
    }

    private function deleteCategory(int $id): void
    {
        if (DB::table('item_types')->where('category_id', $id)->exists()) {
            fail(409, 'in_use', 'Remove the subcategories in this category first.');
        }
        DB::table('categories')->where('id', $id)->delete();
    }

    private function deleteType(int $id, string $name): void
    {
        if (DB::table('sku_catalog')->where('item_type_id', $id)->exists()) {
            fail(409, 'in_use', "Remove the SKUs in {$name} first.");
        }
        $statuses = DB::table('contracts')->whereIn('id', function ($query) use ($id): void {
            $query->select('contract_id')->from('contract_lines')->where('item_type_id', $id);
        })->pluck('status')->all();
        if (in_array('active', $statuses, true)) {
            fail(409, 'in_use', "End the contract that uses {$name} first.");
        }
        if (in_array('draft', $statuses, true)) {
            fail(409, 'in_use', "Remove {$name} from the draft contract first.");
        }
        if (in_array('ended', $statuses, true)) {
            fail(409, 'in_use', "{$name} is on an ended contract, so it stays on record.");
        }
        if (DB::table('items')->where('item_type_id', $id)->exists()) {
            fail(409, 'in_use', "Mats of {$name} are still in the system, so it stays.");
        }
        DB::table('item_types')->where('id', $id)->delete();
    }

    private function deleteArea(int $id): void
    {
        if (DB::table('buildings')->where('area_id', $id)->exists()) {
            fail(409, 'in_use', 'Remove the locations in this area first.');
        }
        DB::table('areas')->where('id', $id)->delete();
    }

    private function deleteZone(int $id): void
    {
        $zone = DB::table('zones')->where('id', $id)->lockForUpdate()->first();
        if ($zone === null) {
            fail(404, 'not_found', 'No such record.');
        }
        $name = (string) $zone->name;
        $contractIds = DB::table('contract_lines')->where('zone_id', $id)->pluck('contract_id');
        if ($contractIds->isNotEmpty()) {
            $statuses = DB::table('contracts')->whereIn('id', $contractIds)->pluck('status')->all();
            if (in_array('active', $statuses, true)) {
                fail(409, 'in_use', "End the contract that uses {$name} first.");
            }
            if (in_array('draft', $statuses, true)) {
                fail(409, 'in_use', "Remove {$name} from the draft contract first.");
            }
            fail(409, 'in_use', "{$name} is on an ended contract, so it stays on record.");
        }
        if (DB::table('items')->where('zone_id', $id)->exists()) {
            fail(409, 'in_use', "Collect the mats in {$name} first.");
        }
        $orderIds = DB::table('order_items')->where('zone_id', $id)->pluck('order_id');
        if ($orderIds->isNotEmpty() && DB::table('orders')->whereIn('id', $orderIds)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
            fail(409, 'in_use', "Finish or remove the order that uses {$name} first.");
        }
        if ($orderIds->isNotEmpty() || DB::table('item_events')->where('zone_id', $id)->exists()) {
            fail(409, 'in_use', "{$name} is already in use, so it stays on record.");
        }
        DB::table('zones')->where('id', $id)->delete();
    }

    private function deleteBuilding(int $id): void
    {
        $building = DB::table('buildings')->where('id', $id)->lockForUpdate()->first();
        if ($building === null) {
            fail(404, 'not_found', 'No such record.');
        }
        $name = (string) $building->name;
        if (DB::table('orders')->where('building_id', $id)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
            fail(409, 'in_use', "Finish or remove the open orders for {$name} first.");
        }
        $zoneIds = DB::table('zones')->where('building_id', $id)->pluck('id');
        $contractIds = DB::table('contracts')->where('building_id', $id)->pluck('id')
            ->merge($zoneIds->isEmpty() ? [] : DB::table('contract_lines')->whereIn('zone_id', $zoneIds)->pluck('contract_id'))
            ->unique()->values();
        if ($contractIds->isNotEmpty()) {
            $shared = DB::table('contracts')->whereIn('id', $contractIds)->whereNotNull('building_id')->where('building_id', '!=', $id)->exists()
                || DB::table('contract_lines as l')->join('zones as z', 'z.id', '=', 'l.zone_id')
                    ->whereIn('l.contract_id', $contractIds)->where('z.building_id', '!=', $id)->exists();
            $statuses = DB::table('contracts')->whereIn('id', $contractIds)->pluck('status')->all();
            if (in_array('active', $statuses, true)) {
                fail(409, 'in_use', $shared ? 'End the contract that also covers another location first.' : "End the contract for {$name} first.");
            }
            if (in_array('draft', $statuses, true)) {
                fail(409, 'in_use', $shared ? "Remove {$name} from the draft contract first." : "Remove the draft contract for {$name} first.");
            }
            if ($shared) {
                fail(409, 'in_use', "{$name} shares an ended contract, so the location stays on record.");
            }
        }
        $this->deleteUnusedPlace($name, $zoneIds->all(), $contractIds->all(), 'building', $id);
        DB::table('buildings')->where('id', $id)->delete();
    }

    private function deleteCustomer(int $id, string $name): void
    {
        if (DB::table('customers')->where('id', $id)->lockForUpdate()->first() === null) {
            fail(404, 'not_found', 'No such record.');
        }
        if (DB::table('orders')->where('customer_id', $id)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
            fail(409, 'in_use', "Finish or remove the open orders for {$name} first.");
        }
        $statuses = DB::table('contracts')->where('customer_id', $id)->pluck('status');
        if ($statuses->contains('active')) {
            fail(409, 'in_use', 'End '.$name."'s ".($statuses->filter(fn ($status) => $status === 'active')->count() > 1 ? 'contracts' : 'contract').' first.');
        }
        if ($statuses->contains('draft')) {
            fail(409, 'in_use', 'Remove the draft '.($statuses->filter(fn ($status) => $status === 'draft')->count() > 1 ? 'contracts' : 'contract')." for {$name} first.");
        }
        $buildingIds = DB::table('buildings')->where('customer_id', $id)->pluck('id');
        $zoneIds = $buildingIds->isEmpty() ? collect() : DB::table('zones')->whereIn('building_id', $buildingIds)->pluck('id');
        $contractIds = DB::table('contracts')->where('customer_id', $id)->pluck('id');
        $this->deleteUnusedPlace($name, $zoneIds->all(), $contractIds->all(), 'customer', $id);
        if ($buildingIds->isNotEmpty()) {
            DB::table('buildings')->whereIn('id', $buildingIds)->delete();
        }
        DB::table('customers')->where('id', $id)->delete();
    }

    /** Deletes ended contracts, their unused visits and zones once nothing live still points at them. */
    private function deleteUnusedPlace(string $name, array $zoneIds, array $contractIds, string $kind, int $buildingId): void
    {
        $zoneIds = array_map('intval', $zoneIds);
        $contractIds = array_map('intval', $contractIds);
        if ($zoneIds !== [] && DB::table('items')->whereIn('zone_id', $zoneIds)->exists()) {
            fail(409, 'in_use', $kind === 'customer' ? "Collect the mats still at {$name} first." : "Collect the mats at {$name} first.");
        }
        $taskIds = $contractIds === [] ? [] : DB::table('tasks')->whereIn('contract_id', $contractIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orders = $kind === 'customer'
            ? DB::table('orders')->where('customer_id', $buildingId)->exists()
            : DB::table('orders')->where('building_id', $buildingId)->exists();
        $history = $orders
            || ($taskIds !== [] && DB::table('tasks')->whereIn('id', $taskIds)->where('status', 'done')->exists())
            || ($taskIds !== [] && DB::table('item_events')->whereIn('task_id', $taskIds)->exists())
            || ($zoneIds !== [] && DB::table('item_events')->whereIn('zone_id', $zoneIds)->exists())
            || ($zoneIds !== [] && DB::table('order_items')->whereIn('zone_id', $zoneIds)->exists())
            || ($taskIds !== [] && DB::table('tasks')->whereIn('previous_task_id', $taskIds)->whereNotIn('id', $taskIds)->exists());
        if ($history) {
            fail(409, 'in_use', $kind === 'customer'
                ? "{$name} has deliveries on record, so the customer stays in the list."
                : "{$name} has deliveries on record, so the location stays in the list.");
        }
        if ($taskIds !== []) {
            DB::table('tasks')->whereIn('id', $taskIds)->update(['previous_task_id' => null]);
            DB::table('tasks')->whereIn('id', $taskIds)->delete();
        }
        if ($contractIds !== []) {
            DB::table('contract_lines')->whereIn('contract_id', $contractIds)->delete();
            DB::table('contracts')->whereIn('id', $contractIds)->delete();
        }
        if ($zoneIds !== []) {
            DB::table('zones')->whereIn('id', $zoneIds)->delete();
        }
    }
}
