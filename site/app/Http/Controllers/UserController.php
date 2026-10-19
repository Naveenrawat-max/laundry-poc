<?php
/** User management by the office. A person with no work behind them can be removed; anyone already on a scan stays. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class UserController extends Controller
{
    public function index(): array
    {
        return ['rows' => User::orderBy('name')->get()];
    }

    public function store(Request $request): array
    {
        return ['user' => User::register($request->all())];
    }

    /** Changes only the fields that were sent. */
    public function update(Request $request, int $id): array
    {
        $user = User::find($id);
        if ($user === null) {
            fail(404, 'not_found', 'No such user.');
        }
        $in = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
            'is_active' => 'sometimes|required|boolean',
            'password' => ['sometimes', 'required', ...User::passwordRules()],
        ]);

        $demoted = ($in['role'] ?? 'office') !== 'office' || !($in['is_active'] ?? true);
        if ($user->is($request->user()) && $demoted) {
            fail(422, 'self_lockout', 'You cannot switch off or demote yourself.');
        }
        if (isset($in['password'])) {
            $in['password_hash'] = Hash::make($in['password']);
            unset($in['password']);
        }
        $user->forceFill($in)->save();
        return ['user' => $user->fresh()];
    }

    /** Removes a login that has never handled a mat, a visit, an order or a handover. */
    public function destroy(Request $request, int $id): array
    {
        $user = User::find($id);
        if ($user === null) {
            fail(404, 'not_found', 'No such user.');
        }
        if ($user->is($request->user())) {
            fail(409, 'in_use', 'You cannot remove the account you are signed in with.');
        }
        $used = DB::table('item_events')->where('user_id', $id)->exists()
            || DB::table('items')->where('holder_user_id', $id)->exists()
            || DB::table('tasks')->where('assigned_to', $id)->exists()
            || DB::table('handovers')->where('giver_id', $id)->exists()
            || DB::table('handovers')->where('receiver_id', $id)->exists()
            || DB::table('handover_items')->where('resolved_by', $id)->exists()
            || DB::table('orders')->where('assigned_to', $id)->orWhere('created_by', $id)->orWhere('washing_user_id', $id)->exists()
            || DB::table('order_items')->where('qc_by', $id)->exists();
        if ($used) {
            fail(409, 'in_use', 'This person has already worked in the system. Switch them off instead of removing them.');
        }
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $id)->delete();
        $user->delete();
        return ['deleted' => true];
    }
}
