#!/usr/bin/env python3
import sys
import subprocess
import json
import argparse

def run_command(cmd):
    try:
        res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode != 0:
            return None, res.stderr.strip()
        return res.stdout.strip(), None
    except Exception as e:
        return None, str(e)

def resize_client(client_id, cpu_cores, ram_gb):
    profile = f"sylo-cliente-{client_id}"
    ram_gi = f"{ram_gb}Gi"
    
    # Identify Deployments
    # We usually check 'custom-web', 'custom-db' (if exists), 'ssh-server'.
    # For this task, we prioritize the main app 'custom-web' and 'custom-db'.

    
    deployments = ["custom-web", "custom-db", "ssh-server"]
    results = {}

    for dep in deployments:
        # Check if deployment exists first
        check_cmd = f"minikube -p {profile} kubectl -- get deployment {dep}"
        out, err = run_command(check_cmd)
        if err: 
            results[dep] = "skipped_not_found"
            continue

        # Set Resources
        # Requests = Limits for Guaranteed QoS (Best practice for consistent performance)
        # Note: RAM must be explicitly constrained.
        
        cmd = f"minikube -p {profile} kubectl -- set resources deployment {dep} " \
              f"--limits=cpu={cpu_cores},memory={ram_gi} --requests=cpu={cpu_cores},memory={ram_gi}"
        
        out, err = run_command(cmd)
        if err:
            results[dep] = f"error: {err}"
        else:
            results[dep] = "resized"

    return {
        "client_id": client_id,
        "new_specs": {"cpu": cpu_cores, "ram": ram_gi},
        "results": results
    }

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Hot-Resize Sylo Client Resources')
    parser.add_argument('client_id', type=str, help='ID of the client')
    parser.add_argument('cpu', type=str, help='New CPU Cores (e.g. 2)')
    parser.add_argument('ram', type=str, help='New RAM GB (e.g. 4)')

    args = parser.parse_args()
    
    result = resize_client(args.client_id, args.cpu, args.ram)
    print(json.dumps(result, indent=2))
