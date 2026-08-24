#!/usr/bin/env python3
"""
Тестовый скрипт для получения и анализа данных со счетчиков Юпитер (и других устройств) через UnicBoard API.

Запуск:
    python3 test_jupiter_api.py
"""

import json
import os
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone


def load_env(env_path: str) -> dict:
    """Простая загрузка переменных из .env файла."""
    config = {}
    if os.path.exists(env_path):
        with open(env_path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    config[k.strip()] = v.strip().strip("'\"")
    return config


class UnicBoardAPIClient:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url.rstrip("/")
        self.token = token

    def _request(
        self, endpoint: str, method: str = "GET", params: dict = None, body: dict = None
    ) -> dict:
        url = f"{self.base_url}{endpoint}"
        if params:
            query = urllib.parse.urlencode(params)
            url += f"?{query}"

        headers = {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
        }
        data_bytes = None
        if body is not None:
            headers["Content-Type"] = "application/json"
            data_bytes = json.dumps(body).encode("utf-8")

        req = urllib.request.Request(
            url, data=data_bytes, headers=headers, method=method
        )
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8")
            return {
                "ok": False,
                "error_code": e.code,
                "error_raw": err_body,
            }
        except Exception as e:
            return {"ok": False, "error_message": str(e)}

    def get_all_devices(self, limit: int = 100) -> dict:
        """GET /api/v1/devices/info"""
        return self._request("/api/v1/devices/info", params={"limit": limit})

    def get_device_info(self, device_id: str) -> dict:
        """GET /api/v1/devices/{device_id}/info"""
        return self._request(f"/api/v1/devices/{device_id}/info")

    def get_device_values(
        self, device_ids: list[str], period_from: str, limit: int = 50
    ) -> dict:
        """POST /api/v1/devices/values"""
        return self._request(
            "/api/v1/devices/values",
            method="POST",
            params={"period_from": period_from, "limit": limit},
            body={"devices_id": device_ids},
        )

    def get_battery_level(self, device_id: str, limit: int = 10) -> dict:
        """GET /api/v1/devices/{device_id}/battery-level"""
        return self._request(
            f"/api/v1/devices/{device_id}/battery-level",
            params={"limit": limit},
        )

    def get_temperatures(self, device_id: str, limit: int = 10) -> dict:
        """GET /api/v1/devices/{device_id}/temperatures"""
        return self._request(
            f"/api/v1/devices/{device_id}/temperatures",
            params={"limit": limit},
        )

    def get_events(self, device_id: str, limit: int = 10) -> dict:
        """GET /api/v1/devices/{device_id}/events"""
        return self._request(
            f"/api/v1/devices/{device_id}/events",
            params={"limit": limit},
        )

    def get_clocks(self, device_id: str) -> dict:
        """GET /api/v1/devices/{device_id}/clocks"""
        return self._request(f"/api/v1/devices/{device_id}/clocks")


def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    env_path = os.path.join(script_dir, ".env")
    env = load_env(env_path)

    token = os.getenv("UNICBOARD_API_TOKEN") or env.get("UNICBOARD_API_TOKEN")
    base_url = (
        os.getenv("UNICBOARD_API_BASE")
        or env.get("UNICBOARD_API_BASE")
        or "https://api.public.data-aggregator.unicboard.by"
    )

    if not token:
        print("❌ Ошибка: UNICBOARD_API_TOKEN не найден в .env или окружении.")
        sys.exit(1)

    client = UnicBoardAPIClient(base_url, token)

    print("🔍 1. Запрос списка приборов пользователя...")
    dev_resp = client.get_all_devices()
    if not dev_resp.get("ok"):
        print("❌ Ошибка получения устройств:", dev_resp)
        sys.exit(1)

    devices = dev_resp.get("payload", [])
    print(f"✅ Найдено устройств: {len(devices)}\n")

    jupiter_devices = []
    for d in devices:
        mod_type = (
            d.get("device_modification", {})
            .get("device_modification_type", {})
        )
        name_ru = mod_type.get("name_ru", "")
        sys_name = mod_type.get("sys_name", "")

        if "юпитер" in name_ru.lower() or "upiter" in sys_name.lower():
            jupiter_devices.append(d)

    print(
        f"🎯 Идентифицировано счетчиков/модемов Юпитер: {len(jupiter_devices)}"
    )

    if len(sys.argv) > 1:
        query = sys.argv[1].strip()
        filtered = [
            d
            for d in devices
            if query == str(d.get("id"))
            or query == str(d.get("manufacturer_serial_number"))
        ]
        if filtered:
            target_devices = filtered
            print(
                f"🔍 Фильтр по запросу '{query}': найдено устройств: {len(target_devices)}"
            )
        else:
            print(
                f"⚠️ Устройство '{query}' не найдено в общем списке. Запрос инфо по UUID..."
            )
            single_resp = client.get_device_info(query)
            if single_resp.get("ok") and single_resp.get("payload"):
                target_devices = [single_resp["payload"]]
            else:
                print(f"❌ Устройство '{query}' не найдено.")
                sys.exit(1)
    else:
        target_devices = jupiter_devices if jupiter_devices else devices

    for idx, dev in enumerate(target_devices, 1):
        dev_id = dev.get("id")
        serial = dev.get("manufacturer_serial_number")
        mod_name = dev.get("device_modification", {}).get("name")
        mod_type = (
            dev.get("device_modification", {})
            .get("device_modification_type", {})
        )
        meter_type = mod_type.get("device_metering_type", {}).get("name_ru")

        print("\n" + "=" * 60)
        print(f"📊 Прибор #{idx}: {mod_type.get('name_ru', 'N/A')} ({mod_name})")
        print(f"   • UUID: {dev_id}")
        print(f"   • Серийный номер: {serial}")
        print(f"   • Тип учета: {meter_type}")
        print(f"   • Производитель: {dev.get('device_manufacturer', {}).get('name')}")

        channels = dev.get("device_channel", [])
        print(f"   • Количество каналов: {len(channels)}")
        for ch in channels:
            ch_num = ch.get("serial_number")
            meters = ch.get("device_meter", [])
            for m in meters:
                last_val = m.get("last_value")
                last_date = m.get("last_value_date")
                unit_mult = m.get("unit_multiplier")
                print(
                    f"     - Канал {ch_num}: последнее значение = {last_val} (дата: {last_date}, множитель: {unit_mult})"
                )

        # 2. Напряжение батареи
        print("\n   🔋 История заряда батареи:")
        bat_resp = client.get_battery_level(dev_id, limit=3)
        if bat_resp.get("ok") and bat_resp.get("payload"):
            for b in bat_resp["payload"]:
                print(f"     • {b.get('date')}: {b.get('value')} В")
        else:
            print("     (нет данных по батарее)")

        # 3. Температура
        print("\n   🌡 История температуры:")
        temp_resp = client.get_temperatures(dev_id, limit=3)
        if temp_resp.get("ok") and temp_resp.get("payload"):
            for t in temp_resp["payload"]:
                print(f"     • {t.get('date')}: {t.get('value')} °C")
        else:
            print("     (нет данных по температуре)")

        # 4. Значения (POST /api/v1/devices/values)
        print("\n   📈 Записи журнала показаний (за последние 7 дней):")
        week_ago = (
            datetime.now(timezone.utc) - timedelta(days=7)
        ).strftime("%Y-%m-%dT00:00:00")
        val_resp = client.get_device_values([dev_id], period_from=week_ago, limit=10)
        if val_resp.get("ok") and val_resp.get("payload"):
            for v in val_resp["payload"]:
                print(
                    f"     • Канал {v.get('channel_number')} | {v.get('date')} | Значение: {v.get('value')} (raw: {v.get('value_raw')}) [{v.get('kind')}]"
                )
        else:
            print(f"     (ошибка или нет данных: {val_resp})")

    print("\n" + "=" * 60)
    print("✨ Тестирование успешно завершено!")


if __name__ == "__main__":
    main()
