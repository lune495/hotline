<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index() { return response()->json(['data' => User::with(['role', 'hotline'])->orderBy('name')->get()]); }
    public function show(User $user) { return response()->json(['data' => $user->load(['role', 'hotline'])]); }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nom' => ['nullable', 'string', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'hotline_id' => ['present', 'nullable', 'integer', 'exists:hotlines,id'],
        ]);
        if ($user && empty($data['password'])) { unset($data['password']); }
        return $data;
    }

    public function store(Request $request)
    {
        return response()->json(['data' => User::create($this->validated($request))->load(['role', 'hotline'])], 201);
    }
    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if ($user->role?->nom === 'admin' && \App\Models\Role::find($data['role_id'])->nom !== 'admin'
            && User::whereHas('role', fn ($q) => $q->where('nom', 'admin'))->count() <= 1) {
            return response()->json(['message' => 'Le dernier administrateur doit conserver son rôle.'], 409);
        }
        $user->update($data);
        return response()->json(['data' => $user->fresh(['role', 'hotline'])]);
    }
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 409);
        }
        if ($user->role?->nom === 'admin' && User::whereHas('role', fn ($q) => $q->where('nom', 'admin'))->count() <= 1) {
            return response()->json(['message' => 'Le dernier administrateur ne peut pas être supprimé.'], 409);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }
}
