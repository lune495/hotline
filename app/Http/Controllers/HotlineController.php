<?php
namespace App\Http\Controllers;

use App\Models\Hotline;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HotlineController extends Controller
{
    public function index() { return response()->json(['data' => Hotline::withCount(['users', 'emails'])->orderBy('nom')->get()]); }
    public function show(Hotline $hotline) { return response()->json(['data' => $hotline->loadCount(['users', 'emails'])]); }

    private function validated(Request $request, ?Hotline $hotline = null): array
    {
        $data = $request->validate([
            'smtp_host' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'smtp_port' => ['sometimes', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['sometimes', Rule::in(['ssl', 'tls'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:4096'],
            'nom' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['ssl', 'tls'])],
            'email' => ['required', 'email', 'max:255'],
            'password' => [$hotline ? 'nullable' : 'required', 'string', 'max:4096'],
            'folder' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);
        if ($hotline && empty($data['password'])) { unset($data['password']); }
        if ($hotline && empty($data['smtp_password'])) { unset($data['smtp_password']); }
        $duplicate = Hotline::where('host', $data['host'])->where('port', $data['port'])
            ->where('email', $data['email'])->where('folder', $data['folder']);
        if ($hotline) { $duplicate->where('id', '!=', $hotline->id); }
        if ($duplicate->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Cette boîte est déjà configurée.']);
        }
        return $data;
    }

    public function store(Request $request) { return response()->json(['data' => Hotline::create($this->validated($request))], 201); }
    public function update(Request $request, Hotline $hotline)
    {
        $hotline->update($this->validated($request, $hotline));
        return response()->json(['data' => $hotline]);
    }
    public function destroy(Hotline $hotline)
    {
        if ($hotline->users()->exists() || $hotline->emails()->exists()) {
            return response()->json(['message' => 'Cette hotline contient des utilisateurs ou des emails. Désactivez-la plutôt.'], 409);
        }
        $hotline->delete();
        return response()->json(['message' => 'Hotline supprimée.']);
    }
}
