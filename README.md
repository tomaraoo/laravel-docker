# rft/laravel-docker

Zero-file Docker runtime (`PHP 8.5 + Apache`) and automatic `composer.json` commands for Laravel applications.

All Docker configuration (`Dockerfile`, `vhost.conf`, `entrypoint.sh`, and `docker-compose.*.yml`) lives inside `vendor/rft/laravel-docker/docker/` so consuming Laravel repositories stay completely free of Docker boilerplate.

---

## Features

- **Zero Docker files in your app root** — `Dockerfile`, Apache `vhost.conf`, and `docker-compose` definitions are executed directly from `vendor/`.
- **Bind-mount runtime (zero baked code or secrets)** — Your Laravel application tree is mounted at `/app` at runtime. No source code or `.env` credentials are ever baked into the image.
- **Patched PHP 8.5 + Apache for Laravel** — Built on official `php:8.5-apache` with `pdo_mysql`, `pdo_pgsql`, `bcmath`, `exif`, `gd`, `intl`, `pcntl`, `zip`, `redis`, core PHP 8.5 OPcache, Composer 2, `mod_rewrite`, `mod_headers`, and PHP2Shell upload/web-shell hardening on `/app/public/storage`, `/app/storage`, and `/app/public/uploads`.
- **Host UID/GID synchronization** — Maps Apache's `www-data` worker user to your local user UID/GID in development (and `48:48` by default in staging/production) so `storage/` and `bootstrap/cache/` are writable without `chown`ing your host files.
- **Environment-aware credentials** — Automatically reads `.env.local` in local development and `.env` in staging/production for both Docker Compose and Laravel.
- **Automatic `composer.json` scripts** — A Composer plugin registers `docker:*` commands into your project's `composer.json` and ensures `.env.local` is added to `.gitignore` upon installation.
- **Cross-platform browser launcher** — `composer docker:open` opens the resolved localhost URL across Linux, WSL2, macOS, and Windows.

---

## Requirements

- Docker Engine & Docker Compose v2 (`docker compose`)
- PHP `^8.5` (for Composer CLI)
- Laravel `11.x`, `12.x`, or `13.x`

---

## Installation

### From Packagist

```bash
composer config allow-plugins.rft/laravel-docker true
composer require rft/laravel-docker
```

### From a Private Git / VCS Repository

Add the repository to your Laravel project's `composer.json`, allow the plugin, and require the package:

```bash
composer config repositories.laravel-docker vcs https://github.com/your-org/laravel-docker.git
composer config allow-plugins.rft/laravel-docker true
composer require rft/laravel-docker:^1.0
```

On install, the Composer plugin automatically:
1. Injects the `docker:*` scripts into your root `composer.json`.
2. Appends `.env.local` to your `.gitignore` if not already ignored.

### Manual Setup / Seeding `.env.local`

You can also run the Artisan installer at any time to initialize `.env.local` or reset `docker:*` scripts:

```bash
# Seed .env.local from .env / .env.example and verify composer.json scripts
php artisan docker:install --init-env

# Overwrite customized docker:* scripts back to package defaults
php artisan docker:install --force
```

### Quick Start (Local Development)

```bash
# 1. Create .env.local (and customize DB_DATABASE / DB_PASSWORD / DOCKER_APP_PORT before first boot)
php artisan docker:install --init-env

# 2. Build the PHP 8.5 + Apache runtime image and start the containers
composer docker:build
composer docker:up

# 3. Run database migrations inside the running app container
composer docker:artisan -- migrate

# 4. Open your browser to http://localhost:<DOCKER_APP_PORT>
composer docker:open
```

---

## Environment Configuration (`.env.local` vs `.env`)

### 1. Local Development (`.env.local`)

When `.env.local` exists in your Laravel project root, both `docker compose` and Laravel automatically read credentials and ports from `.env.local` (even if `.env` exists alongside it).

Example `.env.local`:

```dotenv
APP_NAME="My Laravel App"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8080

DOCKER_APP_PORT=8080

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
FORWARD_DB_PORT=33060
DB_DATABASE=my_laravel_app
DB_USERNAME=laravel
DB_PASSWORD=secret
```

- **Inside the `app` container**: `DB_HOST` defaults to `db` (`3306`), connecting directly to the local MySQL 8.0 container.
- **From your host machine** (e.g., TablePlus / DBeaver / host CLI): Connect to `127.0.0.1` on port `33060` (controlled by `FORWARD_DB_PORT`).

### 2. Staging & Production (`.env`)

On staging or production servers (where `.env.local` does not exist and `.env` sets `APP_ENV=staging` or `APP_ENV=production`, or when passing `-- --staging` / `-- --prod`):

- Uses `docker-compose.staging.yml` or `docker-compose.prod.yml`.
- Runs **only** the `app` container (no local MySQL container; connects to your managed/remote DB configured in `.env`).
- Reads `.env` directly from the mounted host project at `/app/.env`.
- Defaults container worker ownership to `48:48` (RHEL/Rocky `apache`), binds `:80:80` (overridable via `DOCKER_APP_PORT`), applies `:z` SELinux volume labeling, and enables `json-file` log rotation (`20m` x `5`).

---

## Composer Commands

All commands run from your Laravel project root without any Docker files in your repository:

| Command | Equivalent Action | Description |
| :--- | :--- | :--- |
| `composer docker:build` | `docker compose build` | Build the PHP 8.5 + Apache runtime image |
| `composer docker:up` | `docker compose up -d` | Start stack in detached background mode |
| `composer docker:run` | `docker compose up` / `run --rm` | Start stack in foreground, or run a one-off command |
| `composer docker:stop` | `docker compose stop` | Stop running containers without removing them |
| `composer docker:ps` | `docker compose ps` | Show container status and port mappings |
| `composer docker:down` | `docker compose down` | Stop and remove containers and network |
| `composer docker:down-v` | `docker compose down -v` | Stop and remove containers, network, **and DB volumes** |
| `composer docker:logs` | `docker compose logs -f` | Follow container logs |
| `composer docker:restart` | `docker compose restart` | Restart containers |
| `composer docker:artisan` | `docker compose exec app php artisan` | Run Artisan commands inside the `app` container |
| `composer docker:open` | Browser launch | Open `http://localhost:<DOCKER_APP_PORT>` in your default browser |

### Passing Extra Flags & Running Artisan Inside Docker

Append `--` when passing extra arguments through Composer:

```bash
# Rebuild image without cache
composer docker:build -- --no-cache

# Run migrations inside the Docker container
composer docker:artisan -- migrate

# Open an interactive bash shell inside the running app container
./vendor/bin/laravel-docker exec

# Run a one-off container command via docker:run
composer docker:run -- app php artisan migrate --seed

# Explicitly target production or staging compose profiles
composer docker:build -- --prod
composer docker:up -- --prod
composer docker:ps -- --staging

# Preview the exact resolved docker compose command without executing it
composer docker:up -- --dry-run
```

---

## Configuration Variables

Set any of these optional variables in `.env.local` (development) or `.env` (staging/production):

| Variable | Default (Local) | Default (Staging / Prod) | Description |
| :--- | :--- | :--- | :--- |
| `COMPOSE_PROJECT_NAME` | Slugified `APP_NAME` / dir | Slugified `APP_NAME` / dir | Isolates container, image, network, and volume names per project |
| `DOCKER_APP_PORT` | `8080` | `80` (`127.0.0.1:8080` supported) | Host port (or `ip:port`) mapped to container `:80` |
| `DOCKER_OPEN_URL` | `http://localhost:<port>` | `http://localhost` | Explicit URL opened by `composer docker:open` |
| `DOCKER_DB_IMAGE` | `mysql:8.0` | *(n/a)* | Local database image (e.g. `mysql:8.0` or `mysql:5.7`) |
| `FORWARD_DB_PORT` | `33060` | *(n/a)* | Host port mapped to local MySQL `:3306` |
| `DB_ROOT_PASSWORD` | `DB_PASSWORD` (`secret`) | *(n/a)* | Root password for the local MySQL container |
| `HOST_UID` | Current host UID (`id -u`) | `48` | UID mapped to `www-data` inside the container |
| `HOST_GID` | Current host GID (`id -g`) | `48` | GID mapped to `www-data` inside the container |
| `HOST_EXTRA_GID` | `33` | `48` | Supplementary GID added to `www-data` |
| `APP_CODE_PATH` | Project root | Project root (e.g. `/www/app`) | Host path bind-mounted to `/app` |
| `DOCKER_VOLUME_SELINUX_SUFFIX` | *(empty)* | `:z` | Volume mount suffix in staging/prod (set empty on non-SELinux hosts if needed) |

---

## Troubleshooting

### `SQLSTATE[HY000] [1045] Access denied` After Editing `.env.local`

Official `mysql:8.0` only applies `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` **once** — when its persistent `/var/lib/mysql` (`dbdata`) volume is empty on the very first boot.

If you change `DB_USERNAME`, `DB_PASSWORD`, or `DB_DATABASE` in `.env.local` **after** running `composer docker:up` previously, reset the volume so MySQL re-initializes with your updated `.env.local` credentials:

```bash
composer docker:down-v
composer docker:up
composer docker:artisan -- migrate
```

### Missing `sessions` / `cache` Tables (`Base table or view not found`)

Laravel 11+ defaults `SESSION_DRIVER=database` and `CACHE_STORE=database`. Run migrations inside the container right after bringing the stack up:

```bash
composer docker:artisan -- migrate
```

---

## Publishing This Package

The package lives self-contained under `packages/rft/laravel-docker/`. To publish it to its own Git repository so all Laravel projects can require it:

```bash
cd packages/rft/laravel-docker
git init
git add .
git commit -m "Initial release of rft/laravel-docker"
git branch -M main
git remote add origin git@github.com:your-org/laravel-docker.git
git push -u origin main
git tag v1.0.0
git push origin v1.0.0
```

---

## License

Proprietary — Copyright (c) 2026 RFT. All rights reserved. See [LICENSE](LICENSE).

