<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('camera')
            <rect x="3" y="6" width="13" height="12" rx="3"/><path d="m16 10 5-3v10l-5-3"/>
            @break
        @case('recordings')
            <rect x="3" y="3" width="18" height="18" rx="3"/><path d="M7 3v18M17 3v18M3 8h4M3 16h4M17 8h4M17 16h4m-10-7 4 3-4 3z"/>
            @break
        @case('timeline')
            <path d="M4 5v14M20 5v14M4 8h10M10 16h10"/><circle cx="16" cy="8" r="2"/><circle cx="8" cy="16" r="2"/>
            @break
        @case('wall')
            <rect x="3" y="4" width="18" height="14" rx="3"/><path d="M8 22h8M12 18v4m-2-15 5 4-5 4z"/>
            @break
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>
            @break
        @case('shield')
            <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM9 12l2 2 4-4"/>
            @break
        @case('check')
            <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
            @break
        @case('network')
            <rect x="8" y="3" width="8" height="6" rx="2"/><rect x="2" y="16" width="8" height="5" rx="2"/><rect x="14" y="16" width="8" height="5" rx="2"/><path d="M12 9v4M6 16v-3h12v3"/>
            @break
        @case('activity')
            <path d="M3 12h4l3-8 4 16 3-8h4"/>
            @break
        @case('alert')
            <path d="m10.3 4-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0M12 9v4M12 17h.01"/>
            @break
        @case('chevron')
            <path d="m7 10 5 5 5-5"/>
            @break
    @endswitch
</svg>
