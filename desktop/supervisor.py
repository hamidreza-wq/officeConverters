"""End the entire local service tree when the desktop host closes its stdin pipe."""

import ctypes
from ctypes import wintypes
import os
import subprocess
import sys
import threading


def main() -> int:
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR]
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.SetInformationJobObject.argtypes = [
        wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD,
    ]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.TerminateJobObject.argtypes = [wintypes.HANDLE, wintypes.UINT]

    # JOBOBJECT_EXTENDED_LIMIT_INFORMATION: the only set field is LimitFlags.
    information = ctypes.create_string_buffer(144 if ctypes.sizeof(ctypes.c_void_p) == 8 else 112)
    ctypes.c_uint32.from_buffer(information, 16).value = 0x2000
    job = kernel.CreateJobObjectW(None, None)
    if not job or not kernel.SetInformationJobObject(job, 9, information, len(information)):
        raise ctypes.WinError(ctypes.get_last_error())
    if not kernel.AssignProcessToJobObject(job, kernel.GetCurrentProcess()):
        raise ctypes.WinError(ctypes.get_last_error())

    def watch_host() -> None:
        while os.read(sys.stdin.fileno(), 1):
            pass
        kernel.TerminateJobObject(job, 1)

    threading.Thread(target=watch_host, daemon=True).start()
    child = subprocess.Popen(sys.argv[1:], stdin=subprocess.DEVNULL)
    return child.wait()


if __name__ == "__main__":
    raise SystemExit(main())
