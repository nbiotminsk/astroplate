import requests
import json
import os
import sys
from datetime import datetime

API_BASE = "https://api.public.data-aggregator.unicboard.by"
TOKEN = os.environ.get("UNICBOARD_TOKEN", "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhMSI6WyJwcm9kIiwicXh4VD5jemFGLXFPRndyWXpSdDkiLCI-MDh0KHhecWZAZ1JDbUhPbEJUcSIsIiIsZmFsc2UsMzY2MDYyNDAsIjExMDAxPDFGezFCQSMyQUY7MUNBNjNCLDFGKjFCITFCQUIrMSEyIDI3OTg9MSJdfQ.n6Fb6bSu4BXNZ1v0xi91a0EvF9ukbPls18Tw0scY0ebAbI9aSToU_FefRDLQPMZDuuTMXszeQJcmTbGE0XRab0lssRhHUqs2rHeALYGkFSkGaRmz1djQGKjM7mbgFtebWMhZx4pkqTDiu66f4xClIEAmjD9ZFhF9X9y5pvdltm8IrFoEJvXf3JIDwAvL7IlB6q9N_7NV61ohk4Z7tAsyHCbxvFFESYYYhF3sHRLHtEynh6qjE-QGLxaeN6HGOMyKun9p3E77NoVA0h5CJSQXJ6jegj8mAo0V9WOGpAdusN4KM4aUb4mMhOX-7sF_rndYwWpvti844k6K8-T8iLUD3PITJoQMep7gHiz1j2dqP83lbcbQivILoP7Si8A043_LdYgTuGHPV65ft81azMgKTjHHHlsxaeD4R3knygvR9Ss3pGg1pa23PAyodDm7muCGUDPjqXc3vEtBuUOk2X8bTFCgUvVTXaFUOLEiOxQ1ntm7EVYZbVkwUYKrrurfrumZqkCbbQ0GGQqKGgcZwrQ1eB-4Gm2XcDJmGssofoTUmdLfJpvgV78Lv0BvKfZj3ANu-nvrwcRtCfMeTS5ZaiqeARx-UQ27XzBAeXDtVhJCzgCycRzTPg5QkI0c81wdQ1MoKdxEmMXazi1IUNHtQKryOkTZ-4lfD2272_h7OnWjsOc").strip()
if not TOKEN:
    try:
        with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "api_token.txt"), "r", encoding="utf-8") as f:
            TOKEN = f.read().strip()
    except Exception:
        pass

DEVICES = [
    {"id": 8527038, "device_id": "2e50bc92-6c87-4b64-b22e-e96e7997476f", "name": "Fluo"},
    {"id": 8524390, "device_id": "420de7d0-5e14-453d-8ad3-5a1dc3729e34", "name": "Jupiter"},
]

def headers():
    return {
        "Authorization": f"Bearer {TOKEN}",
        "Content-Type": "application/json"
    }

def show(name, resp, offset=600):
    print(f"   [{name}] Status: {resp.status_code}")
    if resp.status_code == 200:
        try:
            data = resp.json()
            print(f"   {json.dumps(data, indent=2, ensure_ascii=False)[:offset]}")
        except Exception:
            print(f"   {resp.text[:offset]}")
    else:
        print(f"   Error: {resp.status_code} - {resp.text[:300]}")

def main():
    print("=== API Test: UnicBoard ===\n")
    if not TOKEN:
        print("ERROR: Задайте токен через переменную окружения UNICBOARD_TOKEN")
        sys.exit(1)

    h = headers()

    print("1) Список устройств  GET /api/v1/devices/info")
    show("devices/info", requests.get(f"{API_BASE}/api/v1/devices/info", headers=h, timeout=15))

    print("\n2) Данные по каждому устройству")
    for dev in DEVICES:
        did = dev["device_id"]
        print(f"\n--- {dev['name']} ({did[:8]}...) ---")
        show("info", requests.get(f"{API_BASE}/api/v1/devices/device_id/info".replace("device_id", did), headers=h, timeout=15))
        show("temperatures?limit=5", requests.get(f"{API_BASE}/api/v1/devices/device_id/temperatures?limit=5".replace("device_id", did), headers=h, timeout=15))
        show("battery-level?limit=5", requests.get(f"{API_BASE}/api/v1/devices/device_id/battery-level?limit=5".replace("device_id", did), headers=h, timeout=15))
        show("events?limit=5", requests.get(f"{API_BASE}/api/v1/devices/device_id/events?limit=5".replace("device_id", did), headers=h, timeout=15))

    print("\n3) POST /api/v1/devices/values")
    url = f"{API_BASE}/api/v1/devices/values"
    try:
        show("devices/values", requests.post(url, headers=h, timeout=15))
    except Exception as e:
        print(f"   Error: {e}")

if __name__ == "__main__":
    main()