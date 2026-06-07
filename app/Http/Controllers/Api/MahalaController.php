<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mahala;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MahalaController extends Controller
{
    public function index(Request $request)
    {
        try {
            $payload = $request->validate([
                'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived', 'all'])],
            ]);
            $status = $payload['status'] ?? 'published';

            $mahalas = Mahala::query()
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->latest()
                ->get()
                ->map(fn (Mahala $mahala) => $this->formatMahala($mahala));

            return response()->json([
                'data' => $mahalas,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju mahala.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $mahala = Mahala::query()->create($this->buildAttributes([
                ...$validated,
                'owner_id' => $request->user()->id,
                'status' => $validated['status'] ?? 'draft',
            ]));

            return response()->json([
                'message' => 'Mahala je uspjesno kreirana.',
                'data' => $this->formatMahala($mahala),
            ], 201);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri kreiranju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri kreiranju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkSave(Request $request)
    {
        $payload = $request->validate([
            'mahalas' => ['required', 'array', 'min:1'],
            'mahalas.*' => ['array'],
        ]);

        try {
            $mahalas = DB::transaction(function () use ($payload) {
                return collect($payload['mahalas'])
                    ->map(function (array $mahalaPayload) {
                        $existingMahala = isset($mahalaPayload['id'])
                            ? Mahala::query()->find($mahalaPayload['id'])
                            : null;

                        $validatedPayload = $mahalaPayload;

                        if ($existingMahala !== null) {
                            unset($validatedPayload['id']);
                        }

                        $validated = validator(
                            $validatedPayload,
                            $this->rules(
                                isUpdate: $existingMahala !== null,
                                mahala: $existingMahala,
                            ),
                        )->validate();

                        if ($existingMahala !== null) {
                            $existingMahala->update($this->buildAttributes($validated, $existingMahala));
                            $existingMahala->refresh();

                            return $this->formatMahala($existingMahala);
                        }

                        $mahala = Mahala::query()->create($this->buildAttributes($validated));

                        return $this->formatMahala($mahala);
                    })
                    ->values()
                    ->all();
            });

            return response()->json([
                'message' => 'Mahale su uspjesno sacuvane.',
                'data' => $mahalas,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri cuvanju mahala.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri cuvanju mahala.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $mahala = Mahala::query()->findOrFail($id);

            return response()->json([
                'data' => $this->formatMahala($mahala),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mahala nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do greske pri ucitavanju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $mahala = Mahala::query()->findOrFail($id);
            $validated = $request->validate($this->rules(isUpdate: true, mahala: $mahala));
            $mahala->update($this->buildAttributes($validated, $mahala));
            $mahala->refresh();

            return response()->json([
                'message' => 'Mahala je uspjesno azurirana.',
                'data' => $this->formatMahala($mahala),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mahala nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri azuriranju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri azuriranju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $mahala = Mahala::query()->findOrFail($id);
            $mahala->delete();

            return response()->json([
                'message' => 'Mahala je uspjesno obrisana.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mahala nije pronadjena.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Doslo je do greske u bazi pri brisanju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Doslo je do neocekivane greske pri brisanju mahale.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function rules(bool $isUpdate = false, ?Mahala $mahala = null): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'id' => array_filter([
                $isUpdate ? 'prohibited' : 'sometimes',
                'string',
                'max:100',
                $isUpdate ? null : Rule::unique('mahalas', 'id'),
            ]),
            'name' => [$required, 'string', 'max:255'],
            'slug' => array_filter([
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('mahalas', 'slug')->ignore($mahala?->getKey(), 'id'),
            ]),
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
            'privacy' => ['sometimes', 'integer', 'min:0'],
            'owner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'level' => ['sometimes', 'integer'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'coordinates' => [$required, 'array', 'min:3'],
            'coordinates.*.latitude' => ['required_with:coordinates', 'numeric', 'between:-90,90'],
            'coordinates.*.longitude' => ['required_with:coordinates', 'numeric', 'between:-180,180'],
            'holes' => ['sometimes', 'array'],
            'holes.*' => ['array', 'min:3'],
            'holes.*.*.latitude' => ['required_with:holes', 'numeric', 'between:-90,90'],
            'holes.*.*.longitude' => ['required_with:holes', 'numeric', 'between:-180,180'],
        ];
    }

    private function buildAttributes(array $validated, ?Mahala $mahala = null): array
    {
        $coordinates = $validated['coordinates'] ?? $mahala?->coordinates ?? [];
        [$latitude, $longitude] = $this->resolveCenter($validated, $coordinates, $mahala);

        return [
            'id' => $mahala?->id ?? $this->resolveId($validated['id'] ?? null, $validated['name']),
            'name' => $validated['name'] ?? $mahala->name,
            'slug' => $validated['slug'] ?? $mahala?->slug ?? $this->resolveSlug($validated['name'] ?? $mahala?->name ?? '', $mahala),
            'status' => $validated['status'] ?? $mahala?->status ?? 'draft',
            'privacy' => $validated['privacy'] ?? $mahala?->privacy ?? 0,
            'owner_id' => array_key_exists('owner_id', $validated) ? $validated['owner_id'] : $mahala?->owner_id,
            'level' => $validated['level'] ?? $mahala?->level ?? 2,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'coordinates' => $coordinates,
            'holes' => $validated['holes'] ?? $mahala?->holes ?? [],
        ];
    }

    private function formatMahala(Mahala $mahala): array
    {
        return [
            'id' => $mahala->id,
            'name' => $mahala->name,
            'slug' => $mahala->slug,
            'status' => $mahala->status,
            'privacy' => $mahala->privacy,
            'owner_id' => $mahala->owner_id,
            'level' => $mahala->level,
            'center' => [
                'latitude' => $mahala->latitude,
                'longitude' => $mahala->longitude,
            ],
            'coordinates' => $mahala->coordinates,
            'holes' => $mahala->holes ?? [],
            'created_at' => $mahala->created_at,
            'updated_at' => $mahala->updated_at,
        ];
    }

    private function resolveId(?string $requestedId, string $name): string
    {
        if ($requestedId !== null && $requestedId !== '') {
            return $requestedId;
        }

        $baseId = 'user-' . Str::slug($name);

        if ($baseId === 'user-') {
            $baseId = 'user-mahala';
        }

        $resolvedId = $baseId;
        $suffix = 2;

        while (Mahala::query()->whereKey($resolvedId)->exists()) {
            $resolvedId = "{$baseId}-{$suffix}";
            $suffix++;
        }

        return $resolvedId;
    }

    private function resolveSlug(string $name, ?Mahala $mahala = null): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = $mahala?->slug ?: Str::slug($mahala?->id ?? '');
        }

        if ($baseSlug === '') {
            $baseSlug = 'mahala';
        }

        $resolvedSlug = $baseSlug;
        $suffix = 2;

        $query = Mahala::query();

        if ($mahala !== null) {
            $query->where($mahala->getKeyName(), '!=', $mahala->getKey());
        }

        while ((clone $query)->where('slug', $resolvedSlug)->exists()) {
            $resolvedSlug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $resolvedSlug;
    }

    private function resolveCenter(array $validated, array $coordinates, ?Mahala $mahala = null): array
    {
        if (array_key_exists('latitude', $validated) && array_key_exists('longitude', $validated)) {
            return [(float) $validated['latitude'], (float) $validated['longitude']];
        }

        if (array_key_exists('coordinates', $validated) && $coordinates !== []) {
            $pointCount = count($coordinates);
            $centerLatitude = array_sum(array_column($coordinates, 'latitude')) / $pointCount;
            $centerLongitude = array_sum(array_column($coordinates, 'longitude')) / $pointCount;

            return [$centerLatitude, $centerLongitude];
        }

        return [
            array_key_exists('latitude', $validated) ? (float) $validated['latitude'] : $mahala?->latitude,
            array_key_exists('longitude', $validated) ? (float) $validated['longitude'] : $mahala?->longitude,
        ];
    }
}
