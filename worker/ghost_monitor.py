#!/usr/bin/python3
from bcc import BPF
import time
import sys
import ctypes as ct
import socket
import struct

# --- BPF PROGRAM ---
# Functionalities:
# 1. Trace Disk Latency: kprobes on blk_account_io_start/done
# 2. Trace TCP Connect: kprobe on tcp_v4_connect

bpf_text = """
#include <uapi/linux/ptrace.h>
#include <linux/blk-mq.h>
#include <net/sock.h>
#include <linux/in.h>

// --- DISK I/O TRACKING ---
struct val_t {
    u64 ts;
};

struct data_t {
    u64 ts;
    u64 delta;
    char comm[TASK_COMM_LEN];
};

BPF_HASH(start, struct request *);
BPF_PERF_OUTPUT(events);

// kprobe: blk_account_io_start(struct request *req)
int trace_req_start(struct pt_regs *ctx, struct request *req) {
    u64 ts = bpf_ktime_get_ns();
    start.update(&req, &ts);
    return 0;
}

// kprobe: blk_account_io_done(struct request *req, ...)
int trace_req_completion(struct pt_regs *ctx, struct request *req) {
    u64 *tsp, delta;
    tsp = start.lookup(&req);
    if (tsp != 0) {
        delta = bpf_ktime_get_ns() - *tsp;
        
        struct data_t data = {};
        data.ts = bpf_ktime_get_ns() / 1000;
        data.delta = delta / 1000; // microseconds
        bpf_get_current_comm(&data.comm, sizeof(data.comm));
        
        events.perf_submit(ctx, &data, sizeof(data));
        start.delete(&req);
    }
    return 0;
}

// --- TCP CONNECTION TRACKING ---
BPF_PERF_OUTPUT(tcp_events);

struct tcp_data_t {
    u64 ts;
    u32 daddr;
    u16 dport;
    char comm[TASK_COMM_LEN];
};

// kprobe: tcp_v4_connect(struct sock *sk, struct sockaddr *uaddr, int addr_len)
// Note: In kprobe, arguments are in registers.
// BCC allows defining function args which map to registers automatically.
int trace_connect(struct pt_regs *ctx, struct sock *sk, struct sockaddr *uaddr) {
    struct tcp_data_t data = {};
    struct sockaddr_in *usin = (struct sockaddr_in *)uaddr;
    
    // Read user-space pointer safely? No, tcp_v4_connect is kernel function, args are kernel pointers if called from kernel
    // or pointers to user space? usually passed from sys_connect.
    // kprobe on tcp_v4_connect receives kernel pointers (bpf_probe_read not strictly needed for direct args if simple, 
    // but better use bpf_probe_read for struct members).

    data.ts = bpf_ktime_get_ns() / 1000;
    
    // Read Dest Address from uaddr
    bpf_probe_read(&data.daddr, sizeof(data.daddr), &usin->sin_addr.s_addr);
    bpf_probe_read(&data.dport, sizeof(data.dport), &usin->sin_port);
    
    bpf_get_current_comm(&data.comm, sizeof(data.comm));
    
    tcp_events.perf_submit(ctx, &data, sizeof(data));
    return 0;
};
"""

print("👻 KERNEL GHOST: Initializing eBPF Probes...")

try:
    b = BPF(text=bpf_text)
    
    # Attach Disk Probes
    disk_probes = [
        ("blk_account_io_start", "blk_account_io_done"),
        ("blk_mq_start_request", "blk_mq_complete_request")
    ]
    
    disk_attached = False
    for start_sym, end_sym in disk_probes:
        if BPF.get_kprobe_functions(start_sym.encode()):
            try:
                b.attach_kprobe(event=start_sym, fn_name="trace_req_start")
                b.attach_kprobe(event=end_sym, fn_name="trace_req_completion")
                print(f"✅ Disk Monitoring attached to {start_sym}/{end_sym}")
                disk_attached = True
                break
            except Exception as e:
                print(f"⚠️ Failed to attach to {start_sym}: {e}")
    
    if not disk_attached:
        print("⚠️  No suitable disk I/O symbols found. Disk monitoring disabled.")

    # Attach TCP Probe
    b.attach_kprobe(event="tcp_v4_connect", fn_name="trace_connect")

    print("✅ GHOST ACTIVE. Monitoring Disk I/O and TCP Traffic...")
    print(f"{'TYPE':<10} {'COMM':<16} {'METRIC':<40}")

    # Callback for Disk I/O
    def print_disk_event(cpu, data, size):
        event = b["events"].event(data)
        print(f"{'DISK_IO':<10} {event.comm.decode('utf-8', 'replace'):<16} Latency: {event.delta} us")

    # Callback for TCP
    def print_tcp_event(cpu, data, size):
        event = b["tcp_events"].event(data)
        try:
           # daddr is network byte order
           daddr = socket.inet_ntoa(struct.pack('<I', event.daddr)) 
           # dport is big endian (network)
           dport = socket.ntohs(event.dport)
           
           # --- FILTERING LOGIC ---
           # Ignore Localhost
           if daddr in ["127.0.0.1", "0.0.0.0", "localhost"]: return
           # Ignore Internal Docker/Minikube Traffic IPs starting with 172. or 192.168.
           if daddr.startswith("172.") or daddr.startswith("192.168."): 
               # We might want to see some internal traffic, but definitely not Database (3306) or API (8001) spam
               if dport in [3306, 8001]: return
           
           print(f"{'TCP_OUT':<10} {event.comm.decode('utf-8', 'replace'):<16} Dest: {daddr}:{dport}")
        except Exception as e:
           pass # Silent ignore parse errors

    b["events"].open_perf_buffer(print_disk_event)
    b["tcp_events"].open_perf_buffer(print_tcp_event)

    while True:
        try:
            b.perf_buffer_poll(timeout=100) # Poll with 100ms timeout
        except KeyboardInterrupt:
            sys.exit()

except Exception as e:
    print(f"❌ KERNEL GHOST FAILURE: {e}")
    # print detailed trace for debugging
    import traceback
    traceback.print_exc()
