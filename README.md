# Challengerator

A web app for running car comparison challenges. Admins create a challenge, upload cars with two images each, and invite voters. Voters work through randomised head-to-head pairs and the app produces a ranked result using an ELO-style rating.

## Features

- **Challenges** — isolated, password-protected spaces; each challenge has its own set of cars and voters
- **Car management** — add cars with two comparison images (A/B) stored on Google Drive
- **Voter management** — invite voters by email or enable self-registration with a code
- **Voting** — voters compare randomised car pairs side-by-side; results are ranked by accumulated rating
- **Google Drive integration** — per-challenge OAuth; images are proxied and cached server-side to avoid rate limits
- **Themes** — Default and Automation colour schemes

## Tech stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 |
| Framework | Symfony 6.4 LTS |
| Database | MariaDB 10.11 |
| Frontend | Hotwire (Turbo + Stimulus), Tailwind CSS |
| File storage | Google Drive API v2 |
| Container | Docker (nginx + php-fpm) |
| Mail (dev) | Mailpit |

## Prerequisites

- Docker and Docker Compose
- Composer (installed on the host, not inside Docker)
- Git

## Local setup

```bash
# 1. Clone
git clone git@github.com:pcmoreno/challengerator.git
cd challengerator

# 2. Create the Docker override with your UID/GID to avoid root-owned files
#    Run `id -u && id -g` to get your numbers
cp docker-compose.override.yml.example docker-compose.override.yml
# Edit docker-compose.override.yml and set user: "UID:GID"

# 3. Start services
docker compose up -d

# 4. Install PHP dependencies (run on the host, not inside the container)
composer install

# 5. Create .env.local and fill in the required values (see section below)
cp .env .env.local

# 6. Run database migrations
make migrate

# 7. Build frontend assets
make assets-build

# 8. Generate an invite code so you can create the first challenge
make generate-invite-codes count=1
```

Open [http://localhost:8000](http://localhost:8000).

## Environment variables

Set these in `.env.local` for local dev (never commit it), and via the deployment platform's secret/env mechanism in production. The committed `.env` documents the expected format; never commit real production values.

| Variable | Description |
|---|---|
| `APP_SECRET` | Symfony app secret — generate with `openssl rand -hex 32` |
| `DATABASE_URL` | MariaDB DSN — default in `.env` already points to the Docker service |
| `COUCHDB_URL` | CouchDB instance used for the vote log. Format `http[s]://USER:PASS@HOST:PORT`. In docker-compose dev/test the value is supplied by the compose env block. |
| `MAILER_DSN` | SMTP DSN — default in `.env` points to Mailpit on port 1025 |
| `GOOGLE_CLIENT_ID` | OAuth 2.0 client ID from Google Cloud Console |
| `GOOGLE_CLIENT_SECRET` | OAuth 2.0 client secret from Google Cloud Console |

## Google Drive setup

Each challenge can connect its own Google Drive folder. To enable this:

1. Go to [Google Cloud Console](https://console.cloud.google.com) and create a project.
2. Enable the **Google Drive API** for the project.
3. Go to **APIs & Services → Credentials → Create credentials → OAuth client ID**.
4. Choose **Web application**.
5. Add an authorised redirect URI:
   - Local: `http://localhost:8000/drive/callback`
   - Production: `https://yourdomain.com/drive/callback`
6. Copy the **Client ID** and **Client Secret** into `.env.local`.
7. On the **OAuth consent screen**, add the Google account you want to use as a test user (required while the app is in testing mode).

Once configured, admins connect Drive from the Car Dashboard of each challenge.

## Development commands

```bash
make assets-build        # compile Tailwind CSS once
make assets-watch        # watch and recompile on change
make migrate             # run pending database migrations
make cache-clear         # clear Symfony cache
make test                # run unit tests
make coverage            # run unit tests with HTML coverage report (var/coverage/)
make fixtures            # load database fixtures
make generate-invite-codes count=N   # generate N invite codes for new challenges
make worker              # run the Messenger worker that writes votes to CouchDB
```

## Running tests

```bash
make test
# or
make test-unit
```

The test database is separate. Set it up once with:

```bash
make test-db
```

## Vote log worker

Votes are persisted to CouchDB asynchronously via Symfony Messenger (Doctrine transport). Without a running worker, votes still succeed and SQL ratings still update — log entries queue up in the `messenger_messages` table until a worker picks them up.

Run the worker in a separate terminal during development:

```bash
make worker
# or, equivalently:
docker compose exec challengeator-app php bin/console messenger:consume vote_log -v --time-limit=3600 --memory-limit=128M
```

Inspect transport state:

```bash
docker compose exec challengeator-app php bin/console messenger:stats
docker compose exec challengeator-app php bin/console messenger:failed:show    # parked failed messages
docker compose exec challengeator-app php bin/console messenger:failed:retry   # retry parked messages
```

## Services

| Service | Local URL |
|---|---|
| App | http://localhost:8000 |
| Mailpit (mail catcher) | http://localhost:8025 |
| MariaDB | localhost:3306 |
| CouchDB (vote log) | http://localhost:5984/_utils |
