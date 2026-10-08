"""Isolated eight-output startup benchmark. No deployment or camera access."""

import argparse
import http.server
import json
import math
import pathlib
import shutil
import socket
import statistics
import subprocess
import threading
import time
import urllib.request

import websocket

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument(
    "--before-ref", required=True, help="Git revision before the startup changes"
)
parser.add_argument(
    "--image",
    default="bigbrotha:startup-test",
    help="Image built with Dockerfile --target test",
)
parser.add_argument(
    "--chromium", default=shutil.which("chromium") or shutil.which("chromium-browser")
)
parser.add_argument("--trials", type=int, default=20)
parser.add_argument(
    "--variants",
    nargs="+",
    choices=["before", "after", "probe250"],
    default=["before", "after", "probe250"],
)
parser.add_argument(
    "--output", type=pathlib.Path, default=pathlib.Path("tmp/live-startup-benchmark")
)
args = parser.parse_args()
if not args.chromium or args.trials < 1:
    parser.error("Chromium and a positive trial count are required")
ROOT = pathlib.Path(__file__).resolve().parents[2]
WORK = args.output.resolve()
WORK.mkdir(parents=True, exist_ok=True)
NAME = "bigbrotha-startup-benchmark"
variant = "before"
# Refuse occupied ports instead of interrupting an existing service.
for port in [28554, 29997, 28889, 28190, 29180, 29322]:
    with socket.socket() as listener:
        listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        listener.bind(("127.0.0.1", port))
with socket.socket(type=socket.SOCK_DGRAM) as listener:
    listener.bind(("127.0.0.1", 28190))


def run(*args, **kwargs):
    result = subprocess.run(
        args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs
    )
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors="replace").strip())
    return result.stdout


(WORK / "player-before.js").write_bytes(
    run(
        "git",
        "-C",
        str(ROOT),
        "show",
        args.before_ref + ":public/js/live-wall-player.js",
    )
)
command_mounts = []
for directory in ["app", "config"]:
    command_mounts += ["-v", f"{ROOT / directory}:/app/{directory}:ro"]
command_mounts += [
    "-v",
    f"{ROOT / 'tests/Performance/live-startup-commands.php'}:/commands.php:ro",
]
# Generate the same FFmpeg commands used by the application, in an isolated image.
commands = json.loads(
    run(
        "docker",
        "run",
        "--rm",
        "--network",
        "none",
        *command_mounts,
        args.image,
        "php",
        "/commands.php",
    )
)
(WORK / "commands.json").write_text(json.dumps(commands, indent=2))


def config(probe):
    paths = {}
    for kind, bframes in [("copy", 0), ("transcode", 2)]:
        paths["fixture-" + kind] = {
            "runOnInit": f"/usr/bin/ffmpeg -nostdin -hide_banner -loglevel error -re -f lavfi -i testsrc2=size=640x360:rate=15 -re -f lavfi -i sine=sample_rate=48000 -c:v libx264 -threads:v 2 -preset ultrafast -g 15 -bf {bframes} -c:a aac -f rtsp -rtsp_transport tcp rtsp://127.0.0.1:28554/fixture-{kind}",
            "runOnInitRestart": True,
        }
    for i in range(8):
        kind = "copy" if i % 2 == 0 else "transcode"
        source = f"source-{i}"
        live = f"live-{i}"
        paths[source] = {
            "runOnDemand": commands["source-" + kind],
            "runOnDemandCloseAfter": "30s",
        }
        paths[live] = {
            "runOnDemand": commands["live-" + kind]
            .replace("source-" + kind, source)
            .replace("-analyzeduration '1000000'", f"-analyzeduration '{probe}'"),
            "runOnDemandCloseAfter": "30s" if variant == "before" else "120s",
        }
    return dict(
        logLevel="warn",
        rtspAddress="127.0.0.1:28554",
        rtspTransports=["tcp"],
        rtmp=False,
        hls=False,
        srt=False,
        moq=False,
        api=True,
        apiAddress="127.0.0.1:29997",
        webrtcAddress="127.0.0.1:28889",
        webrtcAllowOrigins=["http://127.0.0.1:29180"],
        webrtcLocalUDPAddress="127.0.0.1:28190",
        webrtcLocalTCPAddress="127.0.0.1:28190",
        webrtcIPsFromInterfaces=False,
        webrtcAdditionalHosts=["127.0.0.1"],
        pathDefaults={"runOnDemandStartTimeout": "45s"},
        paths=paths,
    )


def api():
    return json.load(urllib.request.urlopen("http://127.0.0.1:29997/v3/paths/list"))[
        "items"
    ]


def wait_ready(names):
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            ready = {p["name"] for p in api() if p.get("ready")}
            if set(names) <= ready:
                return
        except Exception:
            pass
        time.sleep(0.1)
    raise RuntimeError(
        "Paths did not become ready: "
        + str(names)
        + "; inspect the isolated relay log with docker exec "
        + NAME
        + " cat /tmp/relay.log"
    )


def cpu():
    return (
        int(
            run("docker", "exec", NAME, "cat", "/sys/fs/cgroup/cpu.stat")
            .decode()
            .split("usage_usec ")[1]
            .split()[0]
        )
        / 1000000.0
    )


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path in {
            "/public/js/live-wall-player.js",
            "/public/js/vendor/mediamtx-reader.js",
            "/tests/Browser/live-wall-player.html",
            "/tests/Browser/live-wall-player.test.js",
            "/tests/Browser/mediamtx-reader.test.js",
        }:
            p = ROOT / self.path.lstrip("/")
            data = p.read_bytes()
            kind = "text/javascript" if p.suffix == ".js" else "text/html"
        elif self.path == "/player.js":
            data = (
                WORK / "player-before.js"
                if variant == "before"
                else ROOT / "public/js/live-wall-player.js"
            ).read_bytes()
            kind = "text/javascript"
        elif self.path == "/reader.js":
            data = (
                WORK / "reader-before.js"
                if variant == "before"
                else ROOT / "public/js/vendor/mediamtx-reader.js"
            ).read_bytes()
            kind = "text/javascript"
        elif self.path == "/":
            tiles = "".join(
                (
                    f'<div data-webrtc-player data-session-url="/unused" data-reader-url="http://127.0.0.1:29180/reader.js" data-whep-url="http://127.0.0.1:28889/live-{i}/whep" data-access-token="synthetic-token"><video data-role="video" muted playsinline></video><span data-role="message"></span></div>'
                    for i in range(8)
                )
            )
            data = (
                "<!doctype html><html><head><style>body{margin:0;display:grid;grid-template-columns:repeat(4,1fr)}video{width:100%;height:200px}</style><script>\nwindow.peers=[];window.frameTimes=[];window.errors=[];\nconst NativePeer=RTCPeerConnection;\nwindow.RTCPeerConnection=class extends NativePeer {constructor(...a){super(...a);peers.push(this);}};\nwindow.addEventListener('error',e=>errors.push(e.message));\nwindow.addEventListener('unhandledrejection',e=>errors.push(String(e.reason)));\n</script></head><body>"
                + tiles
                + "<script>\ndocument.querySelectorAll('video').forEach((v,i)=>v.requestVideoFrameCallback(()=>{frameTimes[i]=performance.now();}));\n</script><script src=\"/player.js\"></script></body></html>"
            ).encode()
            kind = "text/html"
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Type", kind)
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


server = http.server.ThreadingHTTPServer(("127.0.0.1", 29180), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
chrome = subprocess.Popen(
    [
        args.chromium,
        "--headless=new",
        "--no-sandbox",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        "--mute-audio",
        "--remote-debugging-port=29322",
        "--remote-allow-origins=http://localhost:29322",
        "--user-data-dir=" + str(WORK / "chromium-profile"),
        "about:blank",
    ],
    stdout=(WORK / "chromium.log").open("w"),
    stderr=subprocess.STDOUT,
)
seq = 0
warm = []
results = []
container_created = False
reader_fetched = False
try:
    for _ in range(100):
        try:
            pages = json.load(urllib.request.urlopen("http://127.0.0.1:29322/json"))
            break
        except Exception:
            time.sleep(0.1)
    pages = [p for p in pages if p["type"] == "page"]
    ws = websocket.create_connection(
        pages[0]["webSocketDebuggerUrl"], origin="http://localhost:29322", timeout=60
    )

    def call(method, params={}):
        global seq
        seq += 1
        ws.send(json.dumps(dict(id=seq, method=method, params=params)))
        while True:
            r = json.loads(ws.recv())
            if r.get("id") == seq:
                return r.get("result", {})

    def js(expression):
        r = call(
            "Runtime.evaluate",
            dict(expression=expression, awaitPromise=True, returnByValue=True),
        )
        if "exceptionDetails" in r:
            raise RuntimeError(str(r))
        return r.get("result", {}).get("value")

    call(
        "Emulation.setDeviceMetricsOverride",
        dict(width=1280, height=800, deviceScaleFactor=1, mobile=False),
    )
    call(
        "Page.navigate",
        dict(url="http://127.0.0.1:29180/tests/Browser/live-wall-player.html"),
    )
    time.sleep(0.5)
    for _ in range(100):
        title = js("document.title")
        if title.startswith(("PASS:", "FAIL:")):
            break
        time.sleep(0.1)
    print("REGRESSIONS", title, js("document.body.innerText"), flush=True)
    if not title.startswith("PASS:"):
        raise RuntimeError("Browser regressions failed")
    call("Page.navigate", dict(url="about:blank"))
    run(
        "docker",
        "run",
        "-d",
        "--name",
        NAME,
        "--network",
        "host",
        "--entrypoint",
        "sleep",
        "-v",
        str(WORK) + ":/fixture:ro",
        args.image,
        "infinity",
    )
    container_created = True
    trials = args.trials
    for variant in args.variants:
        probe = 250000 if variant == "probe250" else 1000000
        for case in ["cold", "source-warm", "live-warm"]:
            for trial in range(trials):
                # Reset only the container created by this benchmark for each trial.
                run("docker", "restart", "-t", "0", NAME)
                (WORK / "relay.json").write_text(json.dumps(config(probe)))
                run(
                    "docker",
                    "exec",
                    "-d",
                    NAME,
                    "sh",
                    "-c",
                    "mediamtx /fixture/relay.json > /tmp/relay.log 2>&1",
                )
                wait_ready(["fixture-copy", "fixture-transcode"])
                if not reader_fetched:
                    (WORK / "reader-before.js").write_bytes(
                        urllib.request.urlopen(
                            "http://127.0.0.1:28889/live-0/reader.js"
                        ).read()
                    )
                    reader_fetched = True
                warm = []
                if case != "cold":
                    prefix = "source" if case == "source-warm" else "live"
                    for i in range(8):
                        warm.append(
                            subprocess.Popen(
                                [
                                    "docker",
                                    "exec",
                                    NAME,
                                    "ffmpeg",
                                    "-nostdin",
                                    "-v",
                                    "error",
                                    "-rtsp_transport",
                                    "tcp",
                                    "-i",
                                    f"rtsp://127.0.0.1:28554/{prefix}-{i}",
                                    "-map",
                                    "0",
                                    "-c",
                                    "copy",
                                    "-f",
                                    "null",
                                    "-",
                                ],
                                stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL,
                            )
                        )
                    try:
                        wait_ready([f"{prefix}-{i}" for i in range(8)])
                    except RuntimeError as error:
                        # A probe setting may prevent a pipeline from starting.
                        # Count that trial rather than dropping the measurement.
                        results.append(
                            dict(
                                variant=variant,
                                case=case,
                                trial=trial,
                                stage="prewarm",
                                first_ms=None,
                                all_ms=None,
                                errors=[str(error)],
                            )
                        )
                        (WORK / "results.json").write_text(
                            json.dumps(results, indent=2)
                        )
                        (WORK / f"{variant}-{case}-{trial}.log").write_bytes(
                            run("docker", "exec", NAME, "cat", "/tmp/relay.log")
                        )
                        print(json.dumps(results[-1]), flush=True)
                        for process in warm:
                            process.terminate()
                        for process in warm:
                            process.wait(timeout=5)
                        warm = []
                        continue
                    time.sleep(0.3)
                c0 = cpu()
                start = time.monotonic()
                call("Page.navigate", dict(url="http://127.0.0.1:29180/"))
                time.sleep(0.1)
                while time.monotonic() - start < 25:
                    ready = js("window.frameTimes?.filter(Number.isFinite).length || 0")
                    if ready == 8:
                        break
                    time.sleep(0.1)
                data = js(
                    '({frames:window.frameTimes,errors:window.errors,peers:window.peers?.length,audio:[...document.querySelectorAll("video")].map(v=>v.srcObject?.getAudioTracks().length||0),messages:[...document.querySelectorAll("[data-role=message]")].map(e=>e.textContent)})'
                )
                elapsed = time.monotonic() - start
                c1 = cpu()
                data.update(
                    variant=variant,
                    probe=probe,
                    case=case,
                    trial=trial,
                    cpu_seconds=c1 - c0,
                    cpu_percent=100 * (c1 - c0) / elapsed,
                )
                relay_log = run("docker", "exec", NAME, "cat", "/tmp/relay.log")
                (WORK / f"{variant}-{case}-{trial}.log").write_bytes(relay_log)
                frames = [v for v in data.get("frames", []) if v is not None]
                data.update(
                    first_ms=min(frames) if frames else None,
                    all_ms=max(frames) if len(frames) == 8 else None,
                )
                results.append(data)
                (WORK / "results.json").write_text(json.dumps(results, indent=2))
                print(
                    json.dumps(
                        {
                            k: data[k]
                            for k in [
                                "variant",
                                "case",
                                "trial",
                                "first_ms",
                                "all_ms",
                                "peers",
                                "cpu_percent",
                                "audio",
                                "errors",
                            ]
                        }
                    ),
                    flush=True,
                )
                js("window.BigBrothaLiveWallPlayerModule?.close();true")
                call("Page.navigate", dict(url="about:blank"))
                for p in warm:
                    p.terminate()
                for p in warm:
                    p.wait(timeout=5)
                warm = []
    summary = []
    for v in args.variants:
        for c in ["cold", "source-warm", "live-warm"]:
            rows = [r for r in results if r["variant"] == v and r["case"] == c]
            good = [r for r in rows if r["all_ms"] is not None]
            summary.append(
                dict(
                    variant=v,
                    case=c,
                    trials=len(rows),
                    failures=len(rows) - len(good),
                    first_median_ms=statistics.median((r["first_ms"] for r in good))
                    if good
                    else None,
                    first_p95_ms=sorted(r["first_ms"] for r in good)[
                        math.ceil(len(good) * 0.95) - 1
                    ]
                    if good
                    else None,
                    all_median_ms=statistics.median((r["all_ms"] for r in good))
                    if good
                    else None,
                    all_p95_ms=sorted((r["all_ms"] for r in good))[
                        math.ceil(len(good) * 0.95) - 1
                    ]
                    if good
                    else None,
                    cpu_percent_median=statistics.median(
                        (r["cpu_percent"] for r in good)
                    )
                    if good
                    else None,
                    cpu_seconds_median=statistics.median(r["cpu_seconds"] for r in good)
                    if good
                    else None,
                )
            )
    (WORK / "summary.json").write_text(json.dumps(summary, indent=2))
    print("SUMMARY", json.dumps(summary), flush=True)
finally:
    for p in warm:
        p.terminate()
    if container_created:
        subprocess.run(
            ["docker", "rm", "-f", NAME],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    chrome.terminate()
    chrome.wait(timeout=15)
    server.shutdown()
