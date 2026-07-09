#!/bin/sh
set -eu

image="${1:-random-image-api:latest}"

if ! command -v trivy >/dev/null 2>&1; then
    echo "trivy is required. Install Trivy locally and rerun this script for image: $image" >&2
    exit 127
fi

trivy image --exit-code 1 --severity HIGH,CRITICAL "$image"
