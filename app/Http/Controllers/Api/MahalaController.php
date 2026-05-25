<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mahala;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MahalaController extends Controller
{
    public function index()
    {
        try {
            $mahalas = Mahala::query()
                ->latest()
                ->get()
                ->map(fn (Mahala $mahala) => $this->formatMahala($mahala));

            return response()->json([
                'data' => $mahalas,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving mahalas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules());
            $mahala = Mahala::query()->create($this->buildAttributes($validated));

            return response()->json([
                'message' => 'Mahala created successfully.',
                'data' => $this->formatMahala($mahala),
            ], 201);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while creating the mahala.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while creating the mahala.',
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
                'message' => 'Mahala not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An error occurred while retrieving the mahala.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $mahala = Mahala::query()->findOrFail($id);
            $validated = $request->validate($this->rules(isUpdate: true));
            $mahala->update($this->buildAttributes($validated, $mahala));
            $mahala->refresh();

            return response()->json([
                'message' => 'Mahala updated successfully.',
                'data' => $this->formatMahala($mahala),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mahala not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while updating the mahala.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while updating the mahala.',
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
                'message' => 'Mahala deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Mahala not found.',
                'error' => $e->getMessage(),
            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'A database error occurred while deleting the mahala.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'An unexpected error occurred while deleting the mahala.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function rules(bool $isUpdate = false): array
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
