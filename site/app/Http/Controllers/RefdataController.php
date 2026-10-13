<?php
/** Areas, customers, buildings, zones and mat types: five small tables the office manages the same way. */
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
        'customers' => ['table' => 'customers', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:160',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|string|max:190',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'item-types' => ['table' => 'item_types', 'parent' => null, 'rules' => [
            'name' => 'required|string|max:120',
            'is_active' => 'sometimes|required|boolean',
        ]],
        'buildings' => ['table' => 'buildings', 'parent' => 'customer_id', 'rules' => [
            'area_id' => 'required|integer|min:0',
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
        $values = $request->validate($rules);
        if ($parent !== null) {
            $values[$parent] = (int) $request->route('id');
        }
        return ['row' => $this->find($table, DB::table($table)->insertGetId($values))];
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request): array
    {
        ['table' => $table, 'rules' => $rules] = $this->resource($request);
        $id = (int) $request->route('id');
        $this->find($table, $id);
        $values = $request->validate(array_map(fn (string $rule) => "sometimes|$rule", $rules));
        if ($values !== []) {
            DB::table($table)->where('id', $id)->update($values);
        }
        return ['row' => $this->find($table, $id)];
    }
}
