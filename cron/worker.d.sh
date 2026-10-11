#!/bin/sh
set -u

cd "$(dirname "$0")" || exit 72
trap 'exit 0' TERM INT

while :; do
    php worker.php
    status=$?
    if [ "$status" -ne 0 ]; then
        echo "BASE3 Moodle worker: PHP cycle failed with exit code $status" >&2
        exit "$status"
    fi
    sleep 1
done
