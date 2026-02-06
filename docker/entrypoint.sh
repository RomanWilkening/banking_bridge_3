#!/bin/bash
set -e

# Ensure data directory exists and has correct permissions
mkdir -p /data
chown -R www-data:www-data /data
chmod 755 /data

# Set up cron job for auto-sync (runs every minute, script checks if sync is needed)
echo "* * * * * www-data cd /var/www/html && php bin/auto-sync.php >> /var/log/auto-sync.log 2>&1" > /etc/cron.d/auto-sync
chmod 0644 /etc/cron.d/auto-sync

# Set up cron job for MQTT auto-publish (runs every minute, script checks if publish is needed)
echo "* * * * * www-data cd /var/www/html && php bin/mqtt-publish.php >> /var/log/mqtt-publish.log 2>&1" > /etc/cron.d/mqtt-publish
chmod 0644 /etc/cron.d/mqtt-publish

# Create log files
touch /var/log/auto-sync.log /var/log/mqtt-publish.log
chown www-data:www-data /var/log/auto-sync.log /var/log/mqtt-publish.log

# Start cron in background
service cron start

echo "Banking Bridge started - Auto-sync and MQTT auto-publish cron enabled"

# Start Apache
exec apache2-foreground
