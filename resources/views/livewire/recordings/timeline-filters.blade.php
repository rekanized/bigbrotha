<details class="recording-review__filters" @if (array_sum(array_column($reviewTiles, 'segmentCount')) === 0) open @endif>
    <summary>
        <span><strong>Date range &amp; cameras</strong> <span>{{ $dateFrom }} → {{ $dateTo }}</span></span>
        <span class="recording-review__filter-hint">Change filters</span>
    </summary>
    <form class="recording-review__filter-form" method="GET" action="{{ route('recordings.timeline') }}">
        <div class="recording-review__filter-dates">
            <label class="field-stack"><span>From</span><input class="form-input" type="date" name="date_from" value="{{ $dateFrom }}" required></label>
            <label class="field-stack"><span>To</span><input class="form-input" type="date" name="date_to" value="{{ $dateTo }}" required></label>
        </div>
        @if ($timelineCameraOptions !== [])
            <fieldset class="recording-review__camera-options">
                <legend>Cameras <span>(leave all unchecked to show all)</span></legend>
                @foreach ($timelineCameraOptions as $cameraOption)
                    <label><input type="checkbox" name="camera_ids[]" value="{{ $cameraOption['id'] }}" @checked(in_array((int) $cameraOption['id'], array_map('intval', $selectedCameraIds), true))><span>{{ $cameraOption['name'] }}</span></label>
                @endforeach
            </fieldset>
        @endif
        <input type="hidden" name="active_camera_id" value="{{ $activeCameraId ?? '' }}" data-role="active-camera-input">
        <div class="recording-review__filter-actions">
            <button class="button button--primary" type="submit">Apply filters</button>
            <a class="button button--soft" href="{{ route('recordings.timeline') }}" wire:navigate>Reset filters</a>
            <span>All times: {{ $appSettings->javascriptTimezone() }}</span>
        </div>
    </form>
</details>
