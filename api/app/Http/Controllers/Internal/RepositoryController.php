<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionRepository;
use App\Models\Repository;
use App\Models\RepositoryCreationRequest;
use App\Support\PayloadHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepositoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['ready', 'provisioning', 'failed'])],
        ]);

        return response()->json(Repository::query()
            ->when(isset($input['status']), fn ($query) => $query->where('status', $input['status']))
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate($input['limit'] ?? 50));
    }

    public function show(Repository $repository): JsonResponse
    {
        return response()->json($repository);
    }

    public function byName(string $name): JsonResponse
    {
        return response()->json(Repository::where('name', $name)->firstOrFail());
    }

    public function availability(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,127}$/']]);

        return response()->json(['available' => ! Repository::where('name', $input['name'])->exists()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'requestId' => ['required_without:id', 'prohibits:id', 'uuid'],
            // Accept frozen requests from older Argos conversations without a preflight.
            'id' => ['required_without:requestId', 'prohibits:requestId', 'uuid'],
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,127}$/'],
            'description' => ['required', 'string', 'max:500'],
            'context' => ['required', 'string', 'max:16000'],
        ]);
        $requestId = strtolower($input['requestId'] ?? $input['id']);
        $brief = ['name' => $input['name'], 'description' => $input['description'], 'context' => $input['context']];
        $hash = PayloadHash::make($brief);

        return DB::transaction(function () use ($input, $requestId, $brief, $hash): JsonResponse {
            $creation = RepositoryCreationRequest::whereKey($requestId)->first();
            if ($creation) {
                abort_unless(hash_equals($creation->payload_hash, $hash), 409, 'Creation request already has a different brief.');
                $repository = Repository::findOrFail($creation->repository_id);
            } else {
                $legacy = isset($input['id']) ? Repository::find($requestId) : null;
                $repository = $legacy ?? Repository::firstOrCreate(['name' => $brief['name']], [
                    'id' => isset($input['id']) ? $requestId : (string) Str::uuid(),
                    'description' => $brief['description'], 'context' => $brief['context'],
                    'artifacts_name' => $brief['name'], 'payload_hash' => $hash, 'status' => 'provisioning',
                ]);
            }

            $repository = Repository::whereKey($repository->id)->lockForUpdate()->firstOrFail();
            // Compare authoritative fields so legacy hashes that included the repository ID still work.
            $originalHash = PayloadHash::make([
                'name' => $repository->name, 'description' => $repository->description, 'context' => $repository->context,
            ]);
            abort_unless(hash_equals($originalHash, $hash), 409, 'Repository name already has a different brief.');

            $creation = RepositoryCreationRequest::firstOrCreate(['id' => $requestId], [
                'repository_id' => $repository->id, 'payload_hash' => $hash,
            ]);
            abort_unless($creation->repository_id === $repository->id && hash_equals($creation->payload_hash, $hash),
                409, 'Creation request already has a different brief.');

            if ($repository->status !== 'ready' && ($repository->status === 'failed' || ! $repository->provisioning_queued_at)) {
                // Intake, idempotency mapping and enqueue share the same transaction.
                ProvisionRepository::dispatch($repository->id);
                $repository->update([
                    'status' => 'provisioning', 'provisioning_queued_at' => now(), 'provisioning_error' => null,
                ]);
            }

            return response()->json($repository, $repository->status === 'ready' ? 200 : 202);
        });
    }
}
