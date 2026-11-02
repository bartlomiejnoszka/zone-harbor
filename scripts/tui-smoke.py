#!/usr/bin/env python3
"""Exercise a real PTY with simulated DNS; optionally record an asciicast for agg.

No third-party Python packages or OVH credentials are needed. --live-zone only
browses real DNS and never enters a write form.
"""

import argparse
import codecs
import fcntl
import json
import os
from pathlib import Path
import pty
import select
import shutil
import signal
import struct
import subprocess
import tempfile
import termios
import time


ROOT = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--record", type=Path)
parser.add_argument("--live-zone")
parser.add_argument("--docker-image", help="Use a built runtime image for the live read-only check")
args = parser.parse_args()
if args.docker_image and not args.live_zone:
    parser.error("--docker-image requires --live-zone")
php = shutil.which("php")
if not php:
    raise SystemExit("PHP is required")
frames = []
started = time.monotonic()
pause = 0.8 if args.record else 0.02


class Session:
    def __init__(self, command):
        self.master, self.slave = pty.openpty()
        self.original = termios.tcgetattr(self.slave)
        self.resize(120, 34, notify=False)
        self.process = subprocess.Popen(command, cwd=ROOT, stdin=self.slave,
                                        stdout=self.slave, stderr=self.slave,
                                        start_new_session=True)
        self.output = b""
        self.decoder = codecs.getincrementaldecoder("utf-8")(errors="replace")

    def resize(self, columns, rows, notify=True):
        fcntl.ioctl(self.slave, termios.TIOCSWINSZ, struct.pack("HHHH", rows, columns, 0, 0))
        if notify:
            os.kill(self.process.pid, signal.SIGWINCH)

    def pump(self, seconds):
        until = time.monotonic() + seconds
        while time.monotonic() < until:
            readable, _, _ = select.select([self.master], [], [], min(0.05, max(0, until - time.monotonic())))
            if readable:
                data = os.read(self.master, 65536)
                self.output += data
                if args.record:
                    # PTY reads can split a UTF-8 glyph across chunks. Preserve
                    # it so the recording keeps the same cell widths as the UI.
                    text = self.decoder.decode(data)
                    if text:
                        frames.append([round(time.monotonic() - started, 4), "o", text])

    def send(self, data):
        self.output = b""
        os.write(self.master, data.encode())
        self.pump(0.15)

    def wait(self, marker, timeout=12):
        until = time.monotonic() + timeout
        while marker.encode() not in self.output:
            if self.process.poll() is not None:
                raise AssertionError(f"Terminal exited before {marker!r}; exit={self.process.returncode}")
            if time.monotonic() >= until:
                raise AssertionError(f"Timed out waiting for {marker!r}")
            self.pump(0.05)
        self.pump(pause)

    def finish(self, key="q"):
        self.send(key)
        self.process.wait(timeout=8)
        self.pump(0.1)
        assert self.process.returncode == 0, "Terminal command failed"
        restored = termios.tcgetattr(self.slave)
        # BSD stty may set transient PENDIN while restoring otherwise identical
        # settings. Compare persistent modes, including canonical input and echo.
        restored[3] &= ~getattr(termios, "PENDIN", 0)
        self.original[3] &= ~getattr(termios, "PENDIN", 0)
        assert restored == self.original, f"Terminal mode was not restored: {self.original!r} -> {restored!r}"
        assert b"\x1b[?1049l" in self.output, "Alternate screen was not restored"
        assert b"\x1b[?25h" in self.output, "Cursor was not restored"
        os.close(self.master)
        os.close(self.slave)

    def cleanup(self):
        if self.process.poll() is None:
            self.process.kill()
            self.process.wait()


with tempfile.TemporaryDirectory(prefix="ovh-tui-pty-") as directory:
    command = [php, "bin/console", "dns:tui", args.live_zone] if args.live_zone else [php, "tests/Fixtures/tui-app.php", directory]
    if args.docker_image:
        command = ["docker", "run", "--rm", "-it", "--read-only", "--tmpfs", "/tmp:mode=1777,noexec,nosuid,size=64m",
                   "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true"]
        for name in ["ovh_application_key", "ovh_application_secret", "ovh_consumer_key"]:
            command += ["--mount", f"type=bind,source={ROOT / 'secrets' / name},target=/run/secrets/{name},readonly",
                        "--env", f"{name.upper()}_FILE=/run/secrets/{name}"]
        command += [args.docker_image, "php", "bin/console", "dns:tui", args.live_zone]
    session = Session(command)
    try:
        session.wait("Loaded ", timeout=45 if args.live_zone else 12)
        if args.live_zone:
            session.send("/")
            session.wait("Search:")
            session.send("MX")
            session.send("\r")
            session.wait("Filter applied")
            session.send("\r")
            session.wait("RECORD DETAILS")
            session.send("\x1b")
            session.wait("Back to records")
        else:
            session.send("\x1b[C\x1b[C")
            session.wait("[MX]")
            session.send("\x1b[D\x1b[D")
            session.wait("[All]")
            session.send("\x10")
            session.wait("Command palette")
            session.send("help")
            session.send("\r")
            session.wait("Keyboard help")
            session.send("\x1b")
            session.wait("Back to records")
            session.send("1")
            session.send("/")
            session.wait("Search:")
            session.send("demo")
            session.send("\r")
            session.wait("Filter applied")
            session.send("\r")
            session.wait("Loaded 4 records")
            session.send("4")
            session.wait("HELP")
            session.send("2")
            session.wait("Zones")
            session.send("/")
            session.wait("Search:")
            session.send("google")
            session.send("\r")
            session.wait("Filter applied")
            session.send("\r")
            session.wait("RECORD DETAILS")
            session.send("\x1b")
            session.wait("Back to records")
            session.send("/")
            session.wait("Search:")
            session.send("\x15")
            session.send("\r")
            session.wait("Filter applied")

            def add_preview():
                session.send("a")
                session.wait("Add record")
                session.send("\t\x15_tui-demo-record")
                session.send("\t\x15600")
                session.send('\t"terminal demo"')
                session.send("\r")
                session.wait("CONFIRM CHANGE")

            add_preview()
            session.send("\x1b")
            session.wait("Back to records")
            assert not Path(directory, "records.json").exists(), "Cancelled preview changed state"
            add_preview()
            session.send("y")
            session.wait("OPERATION RESULT")
            saved = json.loads(Path(directory, "records.json").read_text())
            created = next(record for record in saved if record["subDomain"] == "_tui-demo-record")
            assert created["target"] == '"terminal demo"' and created["ttl"] == 600
            original = {record["id"]: record for record in saved if record["id"] != created["id"]}
            session.send("\x1b")
            session.send("r")
            session.wait("Loaded 5 records")
            session.send("/")
            session.wait("Search:")
            session.send("_tui-demo-record")
            session.send("\r")
            session.wait("Filter applied")
            session.send("e")
            session.wait("Edit record")
            session.send('\t\t\x05\x15"updated demo"')
            session.send("\r")
            session.wait("CONFIRM CHANGE")
            assert b"BEFORE" in session.output and b"AFTER" in session.output
            session.send("y")
            session.wait("OPERATION RESULT")
            saved = json.loads(Path(directory, "records.json").read_text())
            updated = next(record for record in saved if record["id"] == created["id"])
            assert updated["target"] == '"updated demo"', "Editor must replace the exact target"
            assert {record["id"]: record for record in saved if record["id"] != created["id"]} == original
            session.send("\x1b")
            session.send("r")
            session.wait("Loaded 5 records")
            session.send("d")
            session.wait("CONFIRM CHANGE")
            session.send("\x1b")
            session.wait("Back to records")
            assert len(json.loads(Path(directory, "records.json").read_text())) == 5
            session.send("d")
            session.wait("CONFIRM CHANGE")
            session.send("y")
            session.wait("OPERATION RESULT")
            remaining = json.loads(Path(directory, "records.json").read_text())
            assert {record["id"]: record for record in remaining} == original, "Other records must be preserved"
            session.send("\x1b")

        session.output = b""
        session.resize(80, 24)
        session.wait("Records")
        assert b"Resize terminal" not in session.output, "Compact terminal should be usable"
        session.send("1")
        session.wait("Zones")
        session.send("2")
        session.wait("Records")
        if not args.live_zone:
            session.send("a")
            session.wait("Add record")
            session.send("\t\x15bad..name")
            session.send("\t\x15-1")
            session.send("\r")
            session.wait("Target cannot be empty")
            session.send("\x1b")
            session.wait("Back to records")
            assert len(json.loads(Path(directory, "records.json").read_text())) == 4
        if not args.live_zone:
            session.send("h")
            session.wait("DOMAIN HEALTH")
            session.wait("Health audit completed")
            session.send("\r")
            session.wait("HEALTH EVIDENCE")
            session.send("\x1b")
            session.wait("DOMAIN HEALTH")
            session.send("\x1b")
            session.wait("Back to records")
        session.output = b""
        session.resize(70, 20)
        session.wait("Resize terminal")
        session.output = b""
        session.resize(120, 34)
        session.wait("Zones")
        session.finish()
    finally:
        session.cleanup()

    # Check raw Ctrl+C restoration separately from normal q exit.
    if not args.live_zone:
        session = Session(command)
        try:
            session.wait("Loaded ")
            session.finish("\x03")
        finally:
            session.cleanup()

if args.record:
    args.record.parent.mkdir(parents=True, exist_ok=True)
    with args.record.open("w") as recording:
        recording.write(json.dumps({"version": 2, "width": 120, "height": 34,
                                    "title": "ZoneHarbor TUI — simulated DNS only"}) + "\n")
        for frame in frames:
            recording.write(json.dumps(frame, ensure_ascii=False) + "\n")
print("PTY checks passed: panels, palette, live search, type filters, forms, confirmation, health evidence, compact layout and terminal restoration."
      if not args.live_zone else "Live read-only PTY check passed: load, MX search, details, resize and exit.")
