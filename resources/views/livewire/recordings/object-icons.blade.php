@php
    $objectOrder = ['person', 'cat', 'dog', 'car', 'motorcycle', 'bus', 'truck'];
    $objectNames = array_values(array_unique(array_filter(array_map(
        static fn ($label) => is_string($label) ? strtolower(trim($label)) : '',
        is_array($objectLabels ?? null) ? $objectLabels : [],
    ))));
    usort($objectNames, static function ($a, $b) use ($objectOrder) {
        $aIndex = array_search($a, $objectOrder, true);
        $bIndex = array_search($b, $objectOrder, true);

        return (($aIndex === false ? 7 : $aIndex) <=> ($bIndex === false ? 7 : $bIndex)) ?: strcmp($a, $b);
    });
@endphp
@if ($objectNames !== [])
    <span class="recording-review__object-icons" data-role="object-icons" aria-hidden="true">
        @foreach (array_slice($objectNames, 0, 2) as $objectName)
            <svg viewBox="0 0 24 24" focusable="false" data-object="{{ $objectName }}"><use href="{{ asset('img/timeline-objects.svg') }}#{{ in_array($objectName, $objectOrder, true) ? $objectName : 'unknown' }}"></use></svg>
        @endforeach
        @if (count($objectNames) > 2)
            <span class="recording-review__object-more">+{{ count($objectNames) - 2 }}</span>
        @endif
    </span>
@endif
