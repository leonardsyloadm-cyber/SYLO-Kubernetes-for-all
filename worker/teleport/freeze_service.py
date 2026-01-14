import os
import sys
import subprocess
import shutil

CHECKPOINT_DIR_BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "checkpoints")

def freeze_process(pid):
    """
    Freeze a process by dumping its state to disk using CRIU.
    """
    print(f"❄️  INITIATING FREEZE SEQUENCE FOR PID: {pid}")
    
    # 1. Verify CRIU presence
    if shutil.which("criu") is None:
        print("❌ CRITICAL ERROR: 'criu' tool not found. Please install it.")
        sys.exit(1)

    # 2. Check if process exists
    try:
        os.kill(int(pid), 0)
    except OSError:
        print(f"❌ ERROR: Process {pid} not found.")
        sys.exit(1)

    # 3. Create Checkpoint Directory
    ckpt_dir = os.path.join(CHECKPOINT_DIR_BASE, str(pid))
    if os.path.exists(ckpt_dir):
        print(f"⚠️  Warning: Checkpoint directory {ckpt_dir} exists. Overwriting...")
        shutil.rmtree(ckpt_dir)
    os.makedirs(ckpt_dir, exist_ok=True)

    # 4. Execute CRIU Dump
    # --tree: dump process tree
    # --shell-job: allow dumping shell jobs
    # --tcp-established: allow dumping established TCP connections
    cmd = [
        "sudo", "criu", "dump",
        "-t", str(pid),
        "-D", ckpt_dir,
        "--shell-job",
        "--tcp-established",
        "-j" # shell job
    ]
    
    print(f"⚡ Executing CRIU dump to {ckpt_dir}...")
    try:
        result = subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        print("✅ FREEZE COMPLETE. Memory flushed to disk.")
        print(f"📂 Location: {ckpt_dir}")
    except subprocess.CalledProcessError as e:
        print("❌ FREEZE FAILED.")
        print(f"Stderr: {e.stderr.decode()}")
        # Clean up failed checkpoint
        # shutil.rmtree(ckpt_dir)

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 freeze_service.py <PID>")
        sys.exit(1)
    
    pid = sys.argv[1]
    freeze_process(pid)
