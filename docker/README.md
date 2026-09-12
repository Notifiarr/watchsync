# Docker

## Branches

- `develop` is the working branch
- `main` is the stable branch
- Pushes to those branches, and tags matching `v*.*.*`, publish `linux/amd64` and `linux/arm64` images to `ghcr.io/notifiarr/watchsync`

## How to use

1. Run `sh docker/build.sh` in the repo root
2. Run `docker compose -f docker/compose.yml up -d --force-recreate`

Dev instance runs on port `:32399`. Bind-mount `root/app/www/public` over `/app/www/public` the same way Dockwatch does for live code. Persist state on `/config` (MariaDB datadir, logs, jobs).
