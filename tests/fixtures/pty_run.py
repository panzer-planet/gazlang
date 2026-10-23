"""
Run bin/gaz on a program with a real terminal (a pty) as its standard input and output, and
print as JSON what the terminal's modes were before, during and after, how the program ended, and
what it printed. PHP can't make a pty, and raw mode can't be seen from a pipe.

    pty_run.py PROGRAM [KEYS_HEX [SIGNAL [ARGS...]]]

KEYS_HEX is written to the terminal once the program has had time to start; SIGNAL (TERM, INT, or
empty for none) is sent after that; ARGS are the program's own arguments. The environment's
PTY_WAIT is how long, in seconds, to let a program run after the keys are typed (default 0.3), for
one that moves by itself. PTY_HOLD, "HEX:EVERY:SECONDS", types those bytes every EVERY seconds for
SECONDS instead, as a key held down does. The window is 132 by 40; PTY_RESIZE, "COLSxROWS",
makes it that size after the keys and tells the program (SIGWINCH), as the terminal would.
PTY_SUSPEND, any value, waits for the program to stop itself after the keys (term_suspend()), notes
the terminal's modes then as "stopped", continues it, waits for the modes to change again or the
program to end, notes them as "resumed", and types the keys once more; the program runs in a process
group of its own in this session, not a session of its own, since a stop is discarded in a group
whose session has given up on it (no parent in the session), which a shell's job never is.

Two limits only guard against a program that hangs, and a busy machine must not trip them: the
program has PTY_START seconds (default 60) to change the terminal's modes or end, and PTY_DEADLINE
seconds (default 30) to end after its keys and signal; one that doesn't is killed and its code is
"timed out". Both end as soon as the program does what they wait for, so a large number costs
nothing unless the program hangs. A test of a program that is meant to hang gives a short deadline.
"""
import fcntl
import json
import os
import select
import signal
import struct
import subprocess
import sys
import termios
import time


def modes(fd):
    lflag = termios.tcgetattr(fd)[3]
    return {"echo": bool(lflag & termios.ECHO), "icanon": bool(lflag & termios.ICANON), "isig": bool(lflag & termios.ISIG)}


program = sys.argv[1]
keys = bytes.fromhex(sys.argv[2]) if len(sys.argv) > 2 else b""
sig = getattr(signal, "SIG" + sys.argv[3]) if len(sys.argv) > 3 and sys.argv[3] else None
program_args = ["--", *sys.argv[4:]] if len(sys.argv) > 4 else []

master, slave = os.openpty()


def resize(cols, rows):
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))


resize(132, 40)
os.set_blocking(master, False)
out = b""


def pump(seconds):
    """Read what the program prints for a while: a terminal's buffer is small, and a program that
    fills it waits for a reader, which would look like a program that never ends"""
    global out
    end = time.time() + seconds
    while time.time() < end:
        if select.select([master], [], [], 0.05)[0]:
            try:
                out += os.read(master, 65536)
            except BlockingIOError:
                pass
            except OSError:
                # the other end is gone (Linux says so this way once the program has ended)
                return


before = modes(slave)
suspend = os.environ.get("PTY_SUSPEND")
process = subprocess.Popen(["bin/gaz", "-f", program, *program_args], stdin=slave, stdout=slave, stderr=slave,
                           start_new_session=not suspend, preexec_fn=os.setpgrp if suspend else None)
# Wait for the program to change the terminal, or to end, not a fixed time: a busy machine starts it late
give_up = time.time() + float(os.environ.get("PTY_START", "60"))
while process.poll() is None and modes(slave) == before and time.time() < give_up:
    pump(0.02)
during = modes(slave)
# then a moment to reach its first read, so that keys and signals arrive after it (a read with a
# timeout of 0.1 has to have waited for nothing)
pump(0.3)
wait = float(os.environ.get("PTY_WAIT", "0.3"))
hold = os.environ.get("PTY_HOLD")
new_size = os.environ.get("PTY_RESIZE")
if new_size:
    cols, rows = new_size.split("x")
    resize(int(cols), int(rows))
    process.send_signal(signal.SIGWINCH)
    pump(wait)
if keys:
    os.write(master, keys)
    if hold:
        held, every, seconds = hold.split(":")
        end = time.time() + float(seconds)
        while time.time() < end:
            os.write(master, bytes.fromhex(held))
            pump(float(every))
    else:
        pump(wait)
if sig:
    process.send_signal(sig)
stopped = resumed = None
if suspend:
    # Wait for the program to stop (or end, which is then its exit: Popen.poll() can't see a child
    # waitpid() here has already reaped, and would call it 0), within the start limit
    give_up = time.time() + float(os.environ.get("PTY_START", "60"))
    pid = status = 0
    while pid != process.pid and time.time() < give_up:
        pid, status = os.waitpid(process.pid, os.WUNTRACED | os.WNOHANG)
        pump(0.05)
    if pid == process.pid and not os.WIFSTOPPED(status):
        process.returncode = os.waitstatus_to_exitcode(status)
    if pid == process.pid and os.WIFSTOPPED(status):
        stopped = modes(slave)
        process.send_signal(signal.SIGCONT)
        give_up = time.time() + float(os.environ.get("PTY_START", "60"))
        while process.poll() is None and modes(slave) == stopped and time.time() < give_up:
            pump(0.02)
        resumed = modes(slave)
        if keys:
            os.write(master, keys)
            pump(wait)
deadline = time.time() + float(os.environ.get("PTY_DEADLINE", "30"))
while process.poll() is None and time.time() < deadline:
    pump(0.1)
if process.poll() is None:
    process.kill()
    process.wait()
    code = "timed out"
else:
    code = process.returncode
pump(0.1)
print(json.dumps({"before": before, "during": during, "after": modes(slave), "stopped": stopped, "resumed": resumed, "code": code, "out": out.decode("utf-8", errors="backslashreplace")}))
