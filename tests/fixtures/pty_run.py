"""
Run bin/gazlang on a program with a real terminal (a pty) as its standard input and output, and
print as JSON what the terminal's modes were before, during and after, how the program ended, and
what it printed. PHP can't make a pty, and raw mode can't be seen from a pipe.

    pty_run.py PROGRAM [KEYS_HEX] [SIGNAL]

KEYS_HEX is written to the terminal once the program has had time to start; SIGNAL (TERM, INT) is
sent after that. The window is 132 by 40.
"""
import fcntl
import json
import os
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
sig = getattr(signal, "SIG" + sys.argv[3]) if len(sys.argv) > 3 else None

master, slave = os.openpty()
fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack("HHHH", 40, 132, 0, 0))
before = modes(slave)
process = subprocess.Popen(["bin/gazlang", "-f", program], stdin=slave, stdout=slave, stderr=slave, start_new_session=True)
time.sleep(0.6)
during = modes(slave)
if keys:
    os.write(master, keys)
    time.sleep(0.3)
if sig:
    process.send_signal(sig)
try:
    code = process.wait(timeout=5)
except subprocess.TimeoutExpired:
    process.kill()
    code = "timed out"
time.sleep(0.1)
os.set_blocking(master, False)
try:
    out = os.read(master, 65536).decode("latin-1")
except OSError:
    out = ""
print(json.dumps({"before": before, "during": during, "after": modes(slave), "code": code, "out": out}))
