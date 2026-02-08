#!/bin/bash
set -e

# Ensure data directory exists and has correct permissions
mkdir -p /data
chown -R www-data:www-data /data
chmod 755 /data

# Set up cron job for auto-sync (runs every minute, script checks if sync is needed)
# IMPORTANT: cron.d files MUST end with a newline!
printf "* * * * * www-data cd /var/www/html && php bin/auto-sync.php >> /var/log/auto-sync.log 2>&1\n" > /etc/cron.d/auto-sync
chmod 0644 /etc/cron.d/auto-sync

# Set up cron job for MQTT auto-publish (runs every minute, script checks if publish is needed)
printf "* * * * * www-data cd /var/www/html && php bin/mqtt-publish.php >> /var/log/mqtt-publish.log 2>&1\n" > /etc/cron.d/mqtt-publish
chmod 0644 /etc/cron.d/mqtt-publish

# Create log files with proper permissions
touch /var/log/auto-sync.log /var/log/mqtt-publish.log
chown www-data:www-data /var/log/auto-sync.log /var/log/mqtt-publish.log

# Start cron daemon and verify it's running
cron
sleep 1
if pgrep -x cron > /dev/null; then
    echo "Cron daemon started successfully"
else
    echo "WARNING: Cron daemon failed to start!"
fi

echo "Banking Bridge started - Auto-sync and MQTT auto-publish cron enabled"
echo "Cron jobs configured:"
cat /etc/cron.d/auto-sync
cat /etc/cron.d/mqtt-publish

# Start Apache
exec apache2-foreground
