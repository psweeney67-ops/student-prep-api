#!/bin/bash

# This script runs every time the container starts.

# Create the necessary directories on the persistent disk (/data).
# The -p flag ensures it doesn't fail if the directories already exist.
mkdir -p /data/jobs
mkdir -p /data/logs

# Set open permissions to ensure the web server (running as www-data) can write to them.
chmod -R 777 /data

# Start the Apache web server in the foreground. This is the last command.
apache2-foreground
