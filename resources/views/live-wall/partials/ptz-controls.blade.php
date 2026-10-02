<div class="wall-ptz-panel" id="{{ $panelId }}" data-ptz-panel popover="manual" hidden role="group" aria-label="Move {{ $camera->name }}">
    <div class="wall-ptz-panel__header">
        <strong>Move camera</strong>
        <button type="button" data-ptz-close aria-label="Close camera controls">&#215;</button>
    </div>
    <div class="wall-ptz-panel__controls">
        <div class="wall-ptz-panel__directions" data-ptz-pan-tilt hidden>
            @foreach (['up-left' => ['↖', 'Move up and left'], 'up' => ['↑', 'Move up'], 'up-right' => ['↗', 'Move up and right'], 'left' => ['←', 'Move left'], 'stop' => ['■', 'Stop camera'], 'right' => ['→', 'Move right'], 'down-left' => ['↙', 'Move down and left'], 'down' => ['↓', 'Move down'], 'down-right' => ['↘', 'Move down and right']] as $command => [$symbol, $label])
                <button type="button" data-ptz-command="{{ $command }}" aria-label="{{ $label }}" title="{{ $label }}">{{ $symbol }}</button>
            @endforeach
        </div>
        <div class="wall-ptz-panel__zoom" data-ptz-zoom hidden>
            <button type="button" data-ptz-command="zoom-in" aria-label="Zoom in" title="Zoom in">+</button>
            <span>Zoom</span>
            <button type="button" data-ptz-command="zoom-out" aria-label="Zoom out" title="Zoom out">−</button>
            <button type="button" data-ptz-command="stop" data-ptz-zoom-stop aria-label="Stop camera" title="Stop camera">■</button>
        </div>
    </div>
    <p class="wall-ptz-panel__status" data-ptz-status role="status">Each press moves briefly.</p>
</div>
