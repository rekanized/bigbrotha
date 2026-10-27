<span wire:poll.5s.visible>
    <span class="status-pill">AI {{ $status['state'] }}{{ ($status['active'] ?? false) ? ' · motion' : '' }}</span>
    @if (($status['provider'] ?? null) === 'CPUExecutionProvider')
        <span class="status-pill">CPU</span>
    @elseif (($status['provider'] ?? null) === 'CUDAExecutionProvider')
        <span class="status-pill">GPU</span>
    @endif
    @if ($status['fallback'] ?? null)
        <span class="status-pill" title="{{ $status['fallback'] }}">GPU unavailable · CPU fallback</span>
    @endif
    @foreach ($status['labels'] ?? [] as $objectLabel)
        <span class="status-pill status-pill--good">{{ ucfirst($objectLabel) }}</span>
    @endforeach
</span>
