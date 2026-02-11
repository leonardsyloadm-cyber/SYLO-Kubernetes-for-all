import requests
import sys

# We know Order 44 was created successfully in previous tests.
OID = 44
URL = f"http://localhost:8000/public/php/auth.php?check_status={OID}"

print(f"Fetching status for Order {OID} from {URL}...")
try:
    r = requests.get(URL)
    print(f"Status Code: {r.status_code}")
    print(f"Response: {r.text}")
    try:
        data = r.json()
        print(f"Parsed Status: {data.get('status')}")
        print(f"Parsed Progress: {data.get('progress')}")
        if data.get('status') == 'completed':
            print("SUCCESS: Status is 'completed'.")
        else:
            print("WARNING: Status is not 'completed'.")
    except:
        print("FAILED to parse JSON")
except Exception as e:
    print(f"Error: {e}")
