import os
import sys
import subprocess
import shutil

CHECKPOINT_DIR_BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "checkpoints")

def thaw_process(identifier):
    """
    Restore a process from disk using CRIU.
    Identifier can be the original PID (folder name).
    """
    print(f"🔥 INITIATING THAW SEQUENCE FOR: {identifier}")

    # 1. Verify CRIU presence
    if shutil.which("criu") is None:
        print("❌ CRITICAL ERROR: 'criu' tool not found.")
        sys.exit(1)

    # 2. Locate Checkpoint Directory
    ckpt_dir = os.path.join(CHECKPOINT_DIR_BASE, str(identifier))
    if not os.path.exists(ckpt_dir):
        print(f"❌ ERROR: Checkpoint not found at {ckpt_dir}")
        sys.exit(1)

    # 3. Execute CRIU Restore
    # -d: detach (restore in background)
    cmd = [
        "sudo", "criu", "restore",
        "-D", ckpt_dir,
        "--shell-job",
        "--tcp-established",
        "-d" 
    ]

    print(f"⚡ Restoring process from {ckpt_dir}...")
    try:
        result = subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        print("✅ THAW COMPLETE. Service Teleported back to RAM.")
    except subprocess.CalledProcessError as e:
        print("❌ THAW FAILED.")
        print(f"Stderr: {e.stderr.decode()}")

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 thaw_service.py <PID_FOLDER_NAME>")
        sys.exit(1)
    
    identifier = sys.argv[1]
    thaw_process(identifier)
