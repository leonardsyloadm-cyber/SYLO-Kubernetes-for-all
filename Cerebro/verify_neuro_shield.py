import requests
import time
import threading

BASE_URL = "http://localhost:8001"
ENDPOINT = "/api/clientes/" # Assuming this exists or returns 404/405 but hits middleware
HEALTH_ENDPOINT = "/"

def test_health():
    try:
        r = requests.get(f"{BASE_URL}{HEALTH_ENDPOINT}")
        print(f"Health Check: {r.status_code} (Expected 200)")
    except Exception as e:
        print(f"Health Check Failed: {e}")

def test_rate_limit():
    print("--- Testing Rate Limit (60 req/min) ---")
    # We need to send > 60 requests.
    for i in range(1, 70):
        try:
            r = requests.get(f"{BASE_URL}{HEALTH_ENDPOINT}")
            status = r.status_code
            if status == 403:
                print(f"Request {i}: BLOCKED (403) - SUCCESSS")
                return
            else:
                pass # print(f"Request {i}: Allowed ({status})")
        except Exception as e:
            print(f"Request {i} failed: {e}")
    print("WARNING: Did not trigger rate limit in 69 requests.")

def test_sqli():
    print("--- Testing SQLi Payload ---")
    payload = {"query": "SELECT * FROM users WHERE name = 'admin' OR '1'='1'"}
    try:
        # POST to trigger body inspection
        r = requests.post(f"{BASE_URL}{HEALTH_ENDPOINT}", json=payload) 
        if r.status_code == 403:
             print(f"SQLi Payload: BLOCKED (403) - SUCCESS")
        else:
             print(f"SQLi Payload: ALLOWED ({r.status_code}) - FAIL")
    except Exception as e:
        print(f"SQLi Test Failed: {e}")

def test_xss():
    print("--- Testing XSS Payload ---")
    payload = {"comment": "<script>alert('hacked')</script>"}
    try:
        r = requests.post(f"{BASE_URL}{HEALTH_ENDPOINT}", json=payload)
        if r.status_code == 403:
             print(f"XSS Payload: BLOCKED (403) - SUCCESS")
        else:
             print(f"XSS Payload: ALLOWED ({r.status_code}) - FAIL")
    except Exception as e:
        print(f"XSS Test Failed: {e}")

if __name__ == "__main__":
    print("Starting Verification...")
    # Verify server is up
    time.sleep(2) # Give server time to start if run consecutively
    test_health()
    test_sqli()
    test_xss()
    test_rate_limit()
