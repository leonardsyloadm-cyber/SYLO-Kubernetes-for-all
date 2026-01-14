import time
import os

print(f"Test Process Running. PID: {os.getpid()}")
i = 0
while True:
    print(f"Count: {i}")
    i += 1
    time.sleep(1)
