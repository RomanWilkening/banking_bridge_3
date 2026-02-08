#!/bin/bash
set -e

# Ensure data directory exists and has correct permissions
mkdir -p /data
chown -R www-data:www-data /data
chmod 755 /data

# Get full path to PHP
PHP_BIN=$(which php)
echo "PHP binary: $PHP_BIN"

# Set up cron job for auto-sync (runs every minute, script checks if sync is needed)
# IMPORTANT: 
# - cron.d files MUST end with a newline
# - Use full path to php
# - Set PATH and other environment variables
cat > /etc/cron.d/auto-sync << EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
DATA_PATH=/data

* * * * * www-data cd /var/www/html && $PHP_BIN bin/auto-sync.php >> /var/log/auto-sync.log 2>&1

EOF
chmod 0644 /etc/cron.d/auto-sync

# Set up cron job for MQTT auto-publish (runs every minute, script checks if publish is needed)
cat > /etc/cron.d/mqtt-publish << EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
DATA_PATH=/data

* * * * * www-data cd /var/www/html && $PHP_BIN bin/mqtt-publish.php >> /var/log/mqtt-publish.log 2>&1

EOF
chmod 0644 /etc/cron.d/mqtt-publish

# Create log files with proper permissions
touch /var/log/auto-sync.log /var/log/mqtt-publish.log /var/log/cron.log
chown www-data:www-data /var/log/auto-sync.log /var/log/mqtt-publish.log
chmod 666 /var/log/auto-sync.log /var/log/mqtt-publish.log /var/log/cron.log

# Validate cron files
echo "Validating cron configuration..."
for f in /etc/cron.d/auto-sync /etc/cron.d/mqtt-publish; do
    echo "=== $f ==="
    cat "$f"
    echo "=== end ==="
done

# Start cron daemon with logging
echo "Starting cron daemon..."
cron -L 15
sleep 2

# Verify cron is running
if pgrep -x cron > /dev/null; then
    echo "Cron daemon started successfully (PID: $(pgrep -x cron))"
else
    echo "WARNING: Cron daemon failed to start!"
    echo "Trying alternative start method..."
    service cron start || true
    sleep 1
    if pgrep -x cron > /dev/null; then
        echo "Cron daemon started via service (PID: $(pgrep -x cron))"
    else
        echo "ERROR: Cron daemon could not be started!"
    fi
fi

echo ""
echo "=========================================="
echo "Banking Bridge started"
echo "Auto-sync and MQTT auto-publish cron enabled"
echo "=========================================="
echo ""

# Start Apache
exec apache2-foreground
