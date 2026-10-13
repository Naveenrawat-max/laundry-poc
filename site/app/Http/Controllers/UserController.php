<?php
/** User management by the office. No delete, only switching off. */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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
}
