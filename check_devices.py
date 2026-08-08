import os
import json
import urllib.request
import ssl

env_path = os.path.join(os.path.dirname(__file__), 'public/api/telegram_bot/.env')
env_vars = {}
if os.path.exists(env_path):
    with open(env_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                env_vars[k.strip()] = v.strip()

unicboard_token = env_vars.get('UNICBOARD_API_TOKEN', '')
base_url = env_vars.get('UNICBOARD_API_BASE', 'https://api.public.data-aggregator.unicboard.by')

# Если в base_url нет http/https, добавляем https://
if not base_url.startswith('http://') and not base_url.startswith('https://'):
    base_url = 'https://' + base_url

print(f"Token present: {bool(unicboard_token)}")
print(f"Target URL: {base_url}/api/v1/devices/info?limit=100")

url = f"{base_url}/api/v1/devices/info?limit=100"
req = urllib.request.Request(url, headers={
    'Authorization': f'Bearer {unicboard_token}',
    'User-Agent': 'Mozilla/5.0'
})

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

try:
    with urllib.request.urlopen(req, context=ctx) as response:
        data = json.loads(response.read().decode('utf-8'))
        payload = data.get('payload', [])
        print(f"\n--- Найдено устройств: {len(payload)} ---")
        
        # Сортируем приборы по серийному номеру
        devices_sorted = []
        for dev in payload:
            raw_serial = dev.get('manufacturer_serial_number')
            try:
                num_serial = int(raw_serial)
            except (ValueError, TypeError):
                num_serial = 0
            devices_sorted.append((num_serial, raw_serial, dev))
        
        devices_sorted.sort(key=lambda x: x[0])
        
        target_limit = 8527038
        matching_count = 0
        
        for num_serial, raw_serial, dev in devices_sorted:
            dev_id = dev.get('id')
            name = dev.get('device_modification', {}).get('name') or dev.get('device_manufacturer', {}).get('name') or 'N/A'
            channels = dev.get('device_channel', [])
            
            is_below = num_serial <= target_limit if num_serial > 0 else True
            if is_below:
                matching_count += 1
                print(f"\n[Прибор #{matching_count}]")
                print(f"  • Серийный номер модема: {raw_serial}")
                print(f"  • Наименование: {name}")
                print(f"  • UUID: {dev_id}")
                for idx, ch in enumerate(channels):
                    ch_serial = ch.get('serial_number')
                    print(f"     -> Канал {idx+1} (Серийный № счетчика): {ch_serial}")

        print(f"\n Всего устройств с серийным номером <= {target_limit}: {matching_count}")
except Exception as e:
    print(f"Error fetching devices: {e}")
