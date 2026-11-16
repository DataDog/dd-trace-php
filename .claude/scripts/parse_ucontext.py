#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.9"
# ///
"""Parse ucontext_t (JSON or Rust Debug format) and print registers."""

import sys, json, re, os, select, termios, tty

GREG_NAMES = [
    "R8", "R9", "R10", "R11", "R12", "R13", "R14", "R15",
    "RDI", "RSI", "RBP", "RBX", "RDX", "RAX", "RCX", "RSP",
    "RIP", "EFLAGS", "CSGSFS", "ERR", "TRAPNO", "OLDMASK", "CR2",
]

EFLAGS_BITS = [
    (0,  "CF"), (2,  "PF"), (4,  "AF"), (6,  "ZF"), (7,  "SF"),
    (8,  "TF"), (9,  "IF"), (10, "DF"), (11, "OF"), (14, "NT"),
    (16, "RF"), (17, "VM"), (18, "AC"), (19, "VIF"), (20, "VIP"), (21, "ID"),
]

TRAP_NAMES = {
    0: "Divide Error", 1: "Debug", 3: "Breakpoint", 4: "Overflow",
    5: "Bound Range Exceeded", 6: "Invalid Opcode", 7: "Device Not Available",
    8: "Double Fault", 10: "Invalid TSS", 11: "Segment Not Present",
    12: "Stack Fault", 13: "General Protection Fault", 14: "Page Fault",
    16: "x87 FPU Error", 17: "Alignment Check", 18: "Machine Check",
    19: "SIMD FP Exception",
}

def eflags_str(val):
    active = [name for bit, name in EFLAGS_BITS if val & (1 << bit)]
    iopl = (val >> 12) & 3
    if iopl:
        active.append(f"IOPL={iopl}")
    return f"0x{val:08x}  [{' '.join(active) if active else '-'}]"

def extract_gregs(text):
    m = re.search(r'gregs:\s*\[([^\]]+)\]', text)
    if m:
        return [int(x.strip(), 0) for x in m.group(1).split(',') if x.strip()]
    try:
        data = json.loads(text)
        if isinstance(data, list):
            return data
        if isinstance(data, dict):
            # New format: ucontext at root of event JSON
            uc = data.get('ucontext')
            # Old format fallback: nested under experimental
            if uc is None:
                experimental = data.get('experimental') or {}
                uc = experimental.get('ucontext')
            if uc is not None:
                data = uc
            gregs = (data.get('uc_mcontext') or data).get('gregs')
            if gregs:
                return gregs
    except json.JSONDecodeError:
        pass
    return None

def read_stdin():
    if not sys.stdin.isatty():
        return sys.stdin.read()
    # TTY: switch to raw mode so pasted data arrives without waiting for Enter
    fd = sys.stdin.fileno()
    old = termios.tcgetattr(fd)
    buf = b""
    try:
        tty.setraw(fd)
        timeout = 30.0  # wait up to 30s for first paste
        while select.select([sys.stdin], [], [], timeout)[0]:
            chunk = os.read(fd, 65536)
            if not chunk:
                break
            buf += chunk
            timeout = 0.2  # 200ms gap after last chunk = done
    finally:
        termios.tcsetattr(fd, termios.TCSADRAIN, old)
    return buf.decode(errors='replace')

def main():
    if len(sys.argv) > 1:
        text = open(sys.argv[1]).read()
    else:
        text = read_stdin()
    gregs = extract_gregs(text)
    if not gregs:
        sys.exit("Error: could not find gregs")

    for i, val in enumerate(gregs):
        name = GREG_NAMES[i] if i < len(GREG_NAMES) else f"REG{i}"
        val &= 0xFFFFFFFFFFFFFFFF  # normalize signed
        if name == "EFLAGS":
            print(f"{name}={eflags_str(val)}")
        elif name == "TRAPNO":
            print(f"{name}={val}  ({TRAP_NAMES.get(val, 'Unknown')})")
        else:
            print(f"{name}=0x{val:016x}")

if __name__ == "__main__":
    main()
