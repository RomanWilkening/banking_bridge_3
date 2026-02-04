#!/bin/bash
set -e

# Ensure data directory exists and has correct permissions
mkdir -p /data
chown -R www-data:www-data /data
chmod 755 /data

# Start Apache
exec apache2-foreground
