#!/bin/sh
# docker/build.sh - Build a Docker image and tag it locally

set -e

IMAGE_NAME="ghcr.io/notifiarr/watchsync"
TAG="local"

usage() {
    echo "Usage: $0 [options]"
    echo "Options:"
    echo "  -h          Show this help message"
    echo "  -t TAG      Set image tag (default: local)"
    exit 0
}

while getopts "ht:" opt; do
    case "$opt" in
        h) usage ;;
        t) TAG="$OPTARG" ;;
        *) usage ;;
    esac
done

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:00Z)"
COMMITS="$(git -C "$ROOT" rev-list --count --all 2>/dev/null || echo 0)"
BRANCH="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
COMMIT="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"

docker build \
    -f "$ROOT/docker/Dockerfile" \
    -t "${IMAGE_NAME}:${TAG}" \
    --build-arg "BUILD_DATE=${BUILD_DATE}" \
    --build-arg "COMMITS=${COMMITS}" \
    --build-arg "BRANCH=${BRANCH}" \
    --build-arg "COMMIT=${COMMIT}" \
    "$ROOT"
