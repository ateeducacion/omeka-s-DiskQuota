# DiskQuota Module for Omeka S

![Screenshot of the module](https://raw.githubusercontent.com/ateeducacion/omeka-s-DiskQuota/refs/heads/main/.github/assets/screenshot1.png)

DiskQuota lets administrators set and enforce per-user and per-site storage limits in Omeka S. It tracks usage and blocks uploads that would exceed configured quotas.

## Features

- Set max storage per user and per site
- Track current storage usage per user/site
- Block uploads that exceed quota
- Admin UI for viewing and managing quotas

## Quick Start (Docker)

- Requirements: Docker Desktop 4+, Make
- Start stack: `make up` then open `http://localhost:8080`
- Stop stack: `make down`

This dev stack uses `erseco/alpine-omeka-s:develop` (service `omeka`) and `mariadb`. Your module is mounted at `/var/www/html/volume/modules/DiskQuota`.

### Sample Data (optional)

- Place a CSV at `data/sample_data.csv` and it will be auto-imported on first boot when CSVImport is available.
- Import manually any time: `make import-sample`

Default admin user (created on first boot):
- `admin@example.com` password: `PLEASE_CHANGEME`

### Useful Make Targets

- `make up` / `make upd`: Run in foreground/background
- `make down` / `make clean`: Stop, optionally remove volumes
- `make logs` / `make ps`: Tail logs, show status
- `make shell`: Shell into the `omeka` container
- `make enable-module`: Enable DiskQuota inside Omeka S
- `make test`: Run PHPUnit tests
- `make package VERSION=x.y.z`: Build a distributable ZIP

Run `make help` to see all targets.

## Manual Installation

1. Download the latest release from the GitHub releases page
2. Extract the ZIP into your Omeka S `modules` directory as `DiskQuota/`
3. In Omeka S admin, go to Modules and click Install on DiskQuota

See the official docs for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules).

## Usage

1. In the admin panel, set user quotas:
   - Go to Users → select a user → User Settings tab
   - Set the desired quota in megabytes (MB)
2. Set site quotas:
   - Go to a Site’s admin → Site admin tab
   - Set the desired quota in megabytes (MB)
3. Use `0` for unlimited.

Uploads that exceed the configured quota are blocked.

## Requirements

- Omeka S 4.x or later
- PHP 8.1+ (module and tests)

## License

Published under the [GNU GPLv3](LICENSE).
