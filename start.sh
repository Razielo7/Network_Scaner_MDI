#!/usr/bin/env bash
set -euo pipefail

# Use Vercel-provided PORT or fall back to 8080 for local runs.
PORT="${PORT:-8080}"

# Update Apache to listen on the chosen port.
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
