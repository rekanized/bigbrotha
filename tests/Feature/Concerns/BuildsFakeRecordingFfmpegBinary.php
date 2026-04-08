<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\File;

trait BuildsFakeRecordingFfmpegBinary
{
    private function fakeFfmpegBinary(string $mode): string
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $rollingMotionScript = static fn (string $profile, bool $logInput = false): string => str_replace(
            ['__PROFILE__', '__LOG_INPUT__'],
            [$profile, $logInput ? '1' : '0'],
            <<<'BASH'
#!/usr/bin/env bash
set -e
profile="__PROFILE__"
log_input="__LOG_INPUT__"

arg_has() {
    local needle="$1"
    shift

    for argument in "$@"; do
        if [[ "$argument" == "$needle" ]]; then
            return 0
        fi
    done

    return 1
}

find_input() {
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" ]]; then
            printf '%s' "$argument"
            return 0
        fi
        previous="$argument"
    done

    return 1
}

timestamp_with_offset() {
    local stamp="$1"
    local offset="$2"

    date -u -d "${stamp:0:4}-${stamp:4:2}-${stamp:6:2} ${stamp:9:2}:${stamp:11:2}:${stamp:13:2} UTC + ${offset} seconds" +%Y%m%d_%H%M%S
}

emit_motion_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\377\377\000\000\000\000\000\000\000\000\000\000'
}

emit_quiet_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

write_segments() {
    local pattern="$1"
    local stamp="$2"
    local profile_name="$3"
    local index="0"
    local label=""
    local timestamp=""
    local path=""

    while (( index < 20 )); do
        case "$profile_name" in
            quiet)
                label="quiet"
                ;;
            preroll-only)
                if (( index < 2 )); then
                    label="motion"
                else
                    label="quiet"
                fi
                ;;
            *)
                case "$index" in
                    4|5|6)
                        label="motion"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
        esac

        timestamp="$(timestamp_with_offset "$stamp" "$index")"
        path="${pattern//%Y%m%d_%H%M%S/$timestamp}"
        mkdir -p "$(dirname "$path")"
        printf '%s-%s' "$label" "$index" > "$path"
        index=$((index + 1))
    done
}

if [[ "$log_input" == "1" ]]; then
    input_log="$(dirname "$0")/ffmpeg-last-input.log"
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" && "$argument" == rtsp://* ]]; then
            mkdir -p "$(dirname "$input_log")"
            printf '%s' "$argument" > "$input_log"
            break
        fi
        previous="$argument"
    done
fi

if arg_has 'rawvideo' "$@"; then
    input="$(find_input "$@" || true)"
    contents=""

    if [[ -n "$input" && -f "$input" ]]; then
        contents="$(<"$input")"
    fi

    if [[ "$contents" == motion* ]]; then
        emit_motion_frame
    else
        emit_quiet_frame
    fi

    exit 0
fi

if arg_has 'concat' "$@"; then
    list_file="$(find_input "$@")"
    output="${!#}"
    count="0"

    while IFS= read -r line; do
        if [[ "$line" == file\ * ]]; then
            count=$((count + 1))
        fi
    done < "$list_file"

    mkdir -p "$(dirname "$output")"
    printf 'capture-%s' "$count" > "$output"
    exit 0
fi

if arg_has 'segment' "$@"; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    write_segments "$pattern" "$stamp" "$profile"
    exit 0
fi

output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH
        );

        $script = match ($mode) {
            'reject-rw-timeout' => <<<'BASH'
#!/usr/bin/env bash
set -e
arg_has() {
    local needle="$1"
    shift

    for argument in "$@"; do
        if [[ "$argument" == "$needle" ]]; then
            return 0
        fi
    done

    return 1
}

if arg_has '-rw_timeout' "$@"; then
    printf '%s\n' 'Option rw_timeout not found.' >&2
    exit 1
fi
if arg_has 'segment' "$@"; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'continuous-log-args' => <<<'BASH'
#!/usr/bin/env bash
set -e
arg_has() {
    local needle="$1"
    shift

    for argument in "$@"; do
        if [[ "$argument" == "$needle" ]]; then
            return 0
        fi
    done

    return 1
}

log_path="$(dirname "$0")/ffmpeg-segment-args.log"
printf '%s\n' "$@" >> "$log_path"
printf '%s\n' '---' >> "$log_path"
if arg_has 'segment' "$@"; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'motion-detected' => $rollingMotionScript('corner'),
            'motion-quiet' => $rollingMotionScript('quiet'),
            'motion-corner' => $rollingMotionScript('corner'),
            'motion-corner-log-input' => $rollingMotionScript('corner', true),
            'motion-late' => $rollingMotionScript('corner'),
            'motion-preroll-only' => $rollingMotionScript('preroll-only'),
            'capture-fails' => <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s\n' 'simulated capture failure' >&2
exit 1
BASH,
            default => <<<'BASH'
#!/usr/bin/env bash
set -e
arg_has() {
    local needle="$1"
    shift

    for argument in "$@"; do
        if [[ "$argument" == "$needle" ]]; then
            return 0
        fi
    done

    return 1
}

if arg_has 'segment' "$@"; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
        };

        $binaryPath = $binaryDirectory.'/ffmpeg-recording-'.$mode.'-'.md5($script).'.sh';

        File::put($binaryPath, $script);
        chmod($binaryPath, 0755);

        return $binaryPath;
    }
}
