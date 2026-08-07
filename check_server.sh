#!/bin/bash

echo "=== Проверка доступности сервера ==="

HOST="176.60.210.112"

echo -n "Ping: "
ping -c 1 -W 2 $HOST > /dev/null 2>&1 && echo "OK" || echo "FAIL"

echo -n "FTP (21): "
timeout 3 bash -c "echo > /dev/tcp/$HOST/21" 2>/dev/null && echo "OPEN" || echo "CLOSED"

echo -n "SSH (22): "
timeout 3 bash -c "echo > /dev/tcp/$HOST/22" 2>/dev/null && echo "OPEN" || echo "CLOSED"

echo -n "MySQL (3306): "
timeout 3 bash -c "echo > /dev/tcp/$HOST/3306" 2>/dev/null && echo "OPEN" || echo "CLOSED"

echo ""
echo "=== Для полной проверки нужен SFTP/SSH клиент ==="
