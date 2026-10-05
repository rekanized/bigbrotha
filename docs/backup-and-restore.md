# Backup and restore

An existing installation needs a matching PostgreSQL dump, private storage, and
`.docker-state` directory. Losing `app.key` makes saved camera passwords and
encrypted settings unreadable. A database-only backup cannot recover footage.
Back up `.env.docker` separately with the same private handling.

Use the same Compose mode as the installation throughout these commands:
`./docker/compose.sh` for published images, `./docker/compose.sh --local` for
source-built images. These examples use published mode.

## Consistent backup

Choose a private backup directory outside the checkout. The commands below use
`../bigbrotha-backup` for illustration. Each backup should have its own directory;
do not overwrite a previous verified backup.

```sh
umask 077
mkdir ../bigbrotha-backup
./docker/compose.sh stop app background relay
./docker/compose.sh exec -T database sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > ../bigbrotha-backup/database.dump
./docker/compose.sh run --rm --no-deps -T --entrypoint tar app -C /app/storage -czf - . > ../bigbrotha-backup/storage.tar.gz
./docker/compose.sh run --rm --no-deps -T --entrypoint tar app -C /app/bootstrap-persist -czf - . > ../bigbrotha-backup/bootstrap.tar.gz
cp .env.docker ../bigbrotha-backup/env.docker
./docker/compose.sh up -d --wait
```

Stopping all three application roles prevents recording, scheduler, and web
writes while the database and media snapshots are taken. This causes downtime;
plan the window. If a backup command fails, restart the stopped roles and mark
that backup incomplete. Do not treat successful file creation alone as recovery
proof. Inspect archive listings and periodically restore into a separate stack.

If SMB is enabled, `storage.tar.gz` contains local previews, review assets,
buffers, and staging, while durable recordings live on the configured share.
Take a matching backup/snapshot of the SMB recording tree during the same window.
Retain the corresponding SMB access configuration and credentials privately.

Encrypt backups at rest, restrict access, and keep a copy away from the Docker
host. Neither backups nor deployment keys belong in Git or a Docker image.

## Restore into a separate empty installation

Create a fresh checkout and private `.env.docker` with a different
`COMPOSE_PROJECT_NAME`, web port, and ICE port. Pin the application image that
created the backup, or a newer version with compatible migrations. Keep the
restored stack isolated from live camera recording and the production SMB share
until its recording policies and storage destination have been reviewed.

From that fresh checkout, before starting any application role:

```sh
umask 077
./docker/compose.sh run --rm --no-deps -T --entrypoint tar app -C /app/bootstrap-persist -xzf - < ../bigbrotha-backup/bootstrap.tar.gz
./docker/compose.sh up -d --wait database
./docker/compose.sh exec -T database sh -c 'exec pg_restore --clean --if-exists --no-owner --no-acl --single-transaction -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < ../bigbrotha-backup/database.dump
./docker/compose.sh run --rm --no-deps -T --entrypoint tar app -C /app/storage -xzf - < ../bigbrotha-backup/storage.tar.gz
./docker/compose.sh up -d --wait
```

Use an empty storage volume for this procedure. Verify all four services are
healthy, the original administrator can sign in, settings/camera passwords
decrypt, saved recording files are available, and playback works before routing
users to the restored installation. The restore may apply pending migrations
when the app starts. A migration or recording prune is not a substitute for a
backup, and rolling back an image does not roll back its database migrations.

Google-only sign-in also needs a valid callback origin. When the hostname
changes, coordinate the provider's authorized redirect URI and the saved app
configuration before attempting sign-in. An isolated restore can instead use
the original hostname through controlled DNS/proxy routing. Avoid sending a
restore-test OAuth callback to the live installation.
