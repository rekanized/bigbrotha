<?php

namespace App\Livewire\LiveWall;

use App\Models\Camera;
use App\Models\LiveWall;
use App\Models\LiveWallTile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component;

class TilesManager extends Component
{
    public ?int $selectedWallId = null;

    /**
     * @var array<string, mixed>
     */
    public array $wallForm = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $tileForms = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $wall = LiveWall::query()
            ->with('tiles')
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->first();

        if ($wall instanceof LiveWall) {
            $this->loadWall($wall);

            return;
        }

        $this->resetEditorState();
    }

    public function createWall(): void
    {
        $this->resetEditorState();
        $this->resetErrorBag();
    }

    public function selectWall(int $wallId): void
    {
        $wall = LiveWall::query()
            ->with('tiles')
            ->findOrFail($wallId);

        $this->loadWall($wall);
        $this->resetErrorBag();
    }

    public function addTile(): void
    {
        $this->tileForms[] = $this->defaultTileForm(
            count($this->tileForms) + 1,
            (string) ($this->wallForm['default_tile_orientation'] ?? 'landscape'),
        );
    }

    public function removeTile(int $index): void
    {
        if (!array_key_exists($index, $this->tileForms)) {
            return;
        }

        unset($this->tileForms[$index]);

        $this->tileForms = array_values($this->tileForms);
        $this->resequenceTiles();
    }

    public function reorderTiles(int $fromIndex, int $toIndex): void
    {
        if (!array_key_exists($fromIndex, $this->tileForms) || !array_key_exists($toIndex, $this->tileForms)) {
            return;
        }

        if ($fromIndex === $toIndex) {
            return;
        }

        $tile = $this->tileForms[$fromIndex];

        array_splice($this->tileForms, $fromIndex, 1);
        array_splice($this->tileForms, $toIndex, 0, [$tile]);

        $this->tileForms = array_values($this->tileForms);
        $this->resequenceTiles();
        $this->statusMessage = null;
        $this->errorMessage = null;
    }

    public function saveWall(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->resetErrorBag();

        $tileForms = array_values(array_filter(
            $this->tileForms,
            fn (array $tile): bool => $this->tileHasCamera($tile),
        ));

        $validated = Validator::make([
            'wallForm' => $this->wallForm,
            'tileForms' => $tileForms,
        ], [
            'wallForm.name' => ['required', 'string', 'max:255'],
            'wallForm.slug' => ['nullable', 'string', 'max:255'],
            'wallForm.description' => ['nullable', 'string', 'max:1000'],
            'wallForm.grid_columns' => ['required', 'integer', 'between:1,6'],
            'wallForm.default_tile_orientation' => ['required', 'in:landscape,portrait,square'],
            'wallForm.is_default' => ['boolean'],
            'wallForm.is_active' => ['boolean'],
            'tileForms' => ['array'],
            'tileForms.*.camera_id' => ['required', 'integer', 'exists:cameras,id'],
            'tileForms.*.orientation' => ['required', 'in:landscape,portrait,square'],
            'tileForms.*.column_span' => ['required', 'integer', 'between:1,4'],
            'tileForms.*.row_span' => ['required', 'integer', 'between:1,4'],
            'tileForms.*.is_enabled' => ['boolean'],
        ])->validate();

        $cameraIds = array_map(
            static fn (array $tile): int => (int) $tile['camera_id'],
            $validated['tileForms'] ?? [],
        );

        if (count($cameraIds) !== count(array_unique($cameraIds))) {
            $this->errorMessage = 'Each camera can only be added once per wall.';

            return;
        }

        DB::transaction(function () use ($validated): void {
            $wall = $this->selectedWallId !== null
                ? LiveWall::query()->findOrFail($this->selectedWallId)
                : new LiveWall();

            $wall->fill([
                'name' => trim((string) $validated['wallForm']['name']),
                'slug' => $this->uniqueSlug(
                    (string) ($validated['wallForm']['slug'] ?: $validated['wallForm']['name']),
                    $wall->exists ? $wall->id : null,
                ),
                'description' => $this->nullableString($validated['wallForm']['description']),
                'grid_columns' => (int) $validated['wallForm']['grid_columns'],
                'default_tile_orientation' => (string) $validated['wallForm']['default_tile_orientation'],
                'is_default' => (bool) $validated['wallForm']['is_default'],
                'is_active' => (bool) $validated['wallForm']['is_active'],
            ]);
            $wall->save();

            if ($wall->is_default) {
                LiveWall::query()
                    ->whereKeyNot($wall->id)
                    ->update(['is_default' => false]);
            }

            $wall->tiles()->delete();

            foreach ($validated['tileForms'] ?? [] as $position => $tile) {
                $wall->tiles()->create([
                    'camera_id' => (int) $tile['camera_id'],
                    'position' => $position + 1,
                    'orientation' => (string) $tile['orientation'],
                    'column_span' => (int) $tile['column_span'],
                    'row_span' => (int) $tile['row_span'],
                    'is_enabled' => (bool) $tile['is_enabled'],
                ]);
            }

            $this->selectedWallId = $wall->id;
            $this->normalizeDefaultWall($wall);
        });

        $wall = LiveWall::query()
            ->with('tiles')
            ->findOrFail($this->selectedWallId);

        $this->loadWall($wall);
        $this->statusMessage = 'Saved wall layout for '.$wall->name.'.';
    }

    public function deleteWall(int $wallId): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        $wall = LiveWall::query()->findOrFail($wallId);
        $name = $wall->name;

        DB::transaction(function () use ($wall): void {
            $wall->delete();
            $this->normalizeDefaultWall();
        });

        $replacementWall = LiveWall::query()
            ->with('tiles')
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->first();

        if ($replacementWall instanceof LiveWall) {
            $this->loadWall($replacementWall);
        } else {
            $this->resetEditorState();
        }

        $this->statusMessage = 'Deleted '.$name.' from wall layouts.';
    }

    public function render(): View
    {
        $walls = LiveWall::query()
            ->withCount([
                'tiles as configured_tiles_count' => fn ($query) => $query->where('is_enabled', true),
            ])
            ->with(['tiles.camera' => fn ($query) => $query->orderBy('name')])
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $cameras = Camera::query()
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->get();

        $selectedWall = $this->selectedWallId !== null
            ? $walls->firstWhere('id', $this->selectedWallId)
            : null;

        return view('livewire.live-wall.tiles-manager', [
            'walls' => $walls,
            'cameras' => $cameras,
            'selectedWall' => $selectedWall,
            'summary' => [
                'walls' => $walls->count(),
                'active_walls' => $walls->where('is_active', true)->count(),
                'tiles' => $walls->sum('configured_tiles_count'),
                'assigned_cameras' => $walls
                    ->flatMap(fn (LiveWall $wall) => $wall->tiles)
                    ->where('is_enabled', true)
                    ->pluck('camera_id')
                    ->unique()
                    ->count(),
            ],
            'orientationOptions' => ['landscape', 'portrait', 'square'],
            'spanOptions' => [1, 2, 3, 4],
        ]);
    }

    private function loadWall(LiveWall $wall): void
    {
        $this->selectedWallId = $wall->id;
        $this->wallForm = [
            'name' => $wall->name,
            'slug' => $wall->slug,
            'description' => $wall->description ?? '',
            'grid_columns' => $wall->grid_columns,
            'default_tile_orientation' => $wall->default_tile_orientation,
            'is_default' => $wall->is_default,
            'is_active' => $wall->is_active,
        ];
        $this->tileForms = $wall->tiles
            ->sortBy(fn (LiveWallTile $tile): array => [$tile->position, $tile->id])
            ->values()
            ->map(fn (LiveWallTile $tile): array => [
                'camera_id' => $tile->camera_id,
                'position' => $tile->position,
                'orientation' => $tile->orientation,
                'column_span' => $tile->column_span,
                'row_span' => $tile->row_span,
                'is_enabled' => $tile->is_enabled,
            ])
            ->all();
        $this->errorMessage = null;
    }

    private function resetEditorState(): void
    {
        $this->selectedWallId = null;
        $this->wallForm = [
            'name' => '',
            'slug' => '',
            'description' => '',
            'grid_columns' => 3,
            'default_tile_orientation' => 'landscape',
            'is_default' => false,
            'is_active' => true,
        ];
        $this->tileForms = [];
        $this->statusMessage = null;
        $this->errorMessage = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTileForm(int $position, string $orientation): array
    {
        return [
            'camera_id' => null,
            'position' => $position,
            'orientation' => $orientation,
            'column_span' => 1,
            'row_span' => 1,
            'is_enabled' => true,
        ];
    }

    private function resequenceTiles(): void
    {
        foreach ($this->tileForms as $index => $tile) {
            $this->tileForms[$index]['position'] = $index + 1;
        }
    }

    /**
     * @param  array<string, mixed>  $tile
     */
    private function tileHasCamera(array $tile): bool
    {
        return ($tile['camera_id'] ?? null) !== null && (string) $tile['camera_id'] !== '';
    }

    private function normalizeDefaultWall(?LiveWall $preferredWall = null): void
    {
        if ($preferredWall?->is_default) {
            LiveWall::query()
                ->whereKeyNot($preferredWall->id)
                ->update(['is_default' => false]);

            return;
        }

        if (LiveWall::query()->where('is_default', true)->exists()) {
            return;
        }

        $fallbackWallId = $preferredWall?->is_active ? $preferredWall->id : null;
        $fallbackWallId ??= LiveWall::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');
        $fallbackWallId ??= LiveWall::query()
            ->orderBy('name')
            ->value('id');

        if ($fallbackWallId === null) {
            return;
        }

        LiveWall::query()->update(['is_default' => false]);
        LiveWall::query()->whereKey($fallbackWallId)->update(['is_default' => true]);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function uniqueSlug(string $source, ?int $ignoreWallId = null): string
    {
        $baseSlug = Str::slug($source);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'wall';
        $candidate = $baseSlug;
        $suffix = 2;

        while (LiveWall::query()
            ->when($ignoreWallId !== null, fn ($query) => $query->whereKeyNot($ignoreWallId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}