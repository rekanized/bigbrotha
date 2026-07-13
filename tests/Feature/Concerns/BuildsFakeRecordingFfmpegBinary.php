<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\File;

trait BuildsFakeRecordingFfmpegBinary
{
    private function fakeFfmpegBinary(string $mode): string
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $rollingMotionScript = static fn (string $profile, bool $logInput = false, bool $logArgs = false): string => str_replace(
            ['__PROFILE__', '__LOG_INPUT__', '__LOG_ARGS__'],
            [$profile, $logInput ? '1' : '0', $logArgs ? '1' : '0'],
            <<<'BASH'
#!/usr/bin/env bash
set -e
profile="__PROFILE__"
log_input="__LOG_INPUT__"
log_args="__LOG_ARGS__"

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
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\377\377\000\000\000\000\000\000\000\000\000\000\200\200\000\000\200\200\000\000\000\000\000\000\000\000\000\000'
}

emit_brief_motion_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\377\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_unconfirmed_motion_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\377\377\000\000\000\000\000\000\000\000\000\000'
}

emit_isolated_pixel_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_adjacent_pair_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_clustered_triplet_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\000\000\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_quiet_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_refresh_glitch_frame() {
    printf '\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000\000'
}

emit_global_luminance_shift_frame() {
    printf '\040\040\040\040\040\040\040\040\040\040\040\040\040\040\040\040\100\100\100\100\100\100\100\100\050\050\050\050\050\050\050\050\100\100\100\100\100\100\100\100\050\050\050\050\050\050\050\050'
}

emit_widespread_refresh_frame() {
    printf '\000\000\000\000\000\000\000\000\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\377\000\000\000\000\000\000\000\000\377\377\377\377\377\377\377\377\000\000\000\000\000\000\000\000'
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
            brief-motion)
                case "$index" in
                    4|5|6)
                        label="brief-motion"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            isolated-pixel)
                case "$index" in
                    4|5|6)
                        label="isolated-pixel"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            adjacent-pair)
                case "$index" in
                    4|5|6)
                        label="adjacent-pair"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            global-luminance-shift)
                case "$index" in
                    4|5|6)
                        label="global-luminance-shift"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            widespread-refresh)
                case "$index" in
                    4|5|6)
                        label="widespread-refresh"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            clustered-triplet)
                case "$index" in
                    4|5|6)
                        label="clustered-triplet"
                        ;;
                    *)
                        label="quiet"
                        ;;
                esac
                ;;
            *)
                case "$index" in
                    4|5|6)
                        if [[ "$profile_name" == "refresh-glitch" ]]; then
                            label="refresh-glitch"
                        else
                            label="motion"
                        fi
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

if [[ "$log_args" == "1" ]]; then
    args_log="$(dirname "$0")/ffmpeg-motion-args.log"
    printf '%s\n' "$@" >> "$args_log"
    printf '%s\n' '---' >> "$args_log"
fi

if arg_has 'rawvideo' "$@"; then
    input="$(find_input "$@" || true)"
    contents=""

    if [[ -n "$input" && -f "$input" ]]; then
        contents="$(<"$input")"
    fi

    if [[ "$contents" == motion* ]]; then
        emit_motion_frame
    elif [[ "$contents" == brief-motion* ]]; then
        emit_brief_motion_frame
    elif [[ "$contents" == unconfirmed-motion* ]]; then
        emit_unconfirmed_motion_frame
    elif [[ "$contents" == isolated-pixel* ]]; then
        emit_isolated_pixel_frame
    elif [[ "$contents" == adjacent-pair* ]]; then
        emit_adjacent_pair_frame
    elif [[ "$contents" == clustered-triplet* ]]; then
        emit_clustered_triplet_frame
    elif [[ "$contents" == refresh-glitch* ]]; then
        emit_refresh_glitch_frame
    elif [[ "$contents" == global-luminance-shift* ]]; then
        emit_global_luminance_shift_frame
    elif [[ "$contents" == widespread-refresh* ]]; then
        emit_widespread_refresh_frame
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
            'continuous-log-input' => <<<'BASH'
#!/usr/bin/env bash
set -e
previous=""
input_log="$(dirname "$0")/ffmpeg-last-input.log"

for argument in "$@"; do
    if [[ "$previous" == "-i" && "$argument" == rtsp://* ]]; then
        mkdir -p "$(dirname "$input_log")"
        printf '%s' "$argument" > "$input_log"
        break
    fi
    previous="$argument"
done

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
            'motion-corner-log-args' => $rollingMotionScript('corner', false, true),
            'motion-late' => $rollingMotionScript('corner'),
            'motion-preroll-only' => $rollingMotionScript('preroll-only'),
            'motion-brief-local' => $rollingMotionScript('brief-motion'),
            'motion-isolated-pixel' => $rollingMotionScript('isolated-pixel'),
            'motion-adjacent-pair' => $rollingMotionScript('adjacent-pair'),
            'motion-clustered-triplet' => $rollingMotionScript('clustered-triplet'),
            'motion-refresh-glitch' => $rollingMotionScript('refresh-glitch'),
            'motion-global-luminance-shift' => $rollingMotionScript('global-luminance-shift'),
            'motion-widespread-refresh' => $rollingMotionScript('widespread-refresh'),
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
