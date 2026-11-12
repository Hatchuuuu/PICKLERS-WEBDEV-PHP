# PICKLERS

A Symfony 7.4 application using Twig, Symfony Security, Doctrine DBAL and Doctrine migrations. All application requests enter `public/index.php` and are dispatched by Symfony, including compatibility URLs such as `/auth.php`. Frontend CSS and JavaScript are static assets; no Node build is required to serve the application.

## Run with Docker

Install Docker Desktop with Linux containers and Docker Compose. Local PHP, Composer, Node, Apache and XAMPP are not required.

1. If `.env.docker` does not exist, copy `.env.docker.example` to `.env.docker` and set two different random database passwords. Retain an existing file and its credentials. Keep this file private.
2. Build and start:

   ```sh
   docker compose --env-file .env.docker up --build --wait app
   ```

3. Open [PICKLERS](http://localhost:8080). Change `DOCKER_HTTP_PORT` if needed.

The default build uses production settings: debug off, no demo accounts, no simulated payments and no development dependencies. The database is internal to Docker and the HTTP port binds to loopback. `setup` waits for database health, installs the base schema, applies all Doctrine migrations and clears/warms the Symfony cache before Apache starts. A failed setup prevents the app from starting. Re-running setup preserves existing application rows; back up before applying future schema changes.

The web process runs as `www-data`, serves only `public/`, and refuses uploaded executable files. PHP errors and Apache access/error logs go to Docker logs. These choices follow the [Symfony web-server guidance](https://symfony.com/doc/7.4/setup/web_server_configuration.html) and [Compose startup dependency guidance](https://docs.docker.com/compose/how-tos/startup-order/).

```sh
docker compose --env-file .env.docker ps --all
docker compose --env-file .env.docker logs --tail=100 app setup database
docker compose --env-file .env.docker exec app php bin/console about
docker compose --env-file .env.docker exec app php bin/console doctrine:migrations:status
docker compose --env-file .env.docker down
```

Named volumes retain MySQL data, application state, private documents, facility uploads, PHP sessions and the generated application secret across container replacement. `down` preserves volumes. **`down --volumes` deletes those records and files.** Back up the database and file volumes together. Changing database passwords in `.env.docker` does not rotate an already-initialized database's credentials; rotate them in MySQL as well.

### Container and image names

The Compose project is `picklers`. Container names follow Compose's `<project>-<service>-<replica>` convention; fixed `container_name` values are unnecessary and prevent scaling. See the [Docker Compose service reference](https://docs.docker.com/reference/compose-file/services/#container_name).

| Container | Purpose | Expected state |
| --- | --- | --- |
| `picklers-app-1` | Apache + PHP + Symfony | Running, healthy |
| `picklers-database-1` | Persistent application MySQL | Running, healthy |
| `picklers-setup-1` | Schema, migrations and cache initialization | Exited (0) |
| `picklers-test-database-1` | Disposable MySQL for tests | Running, healthy while testing |
| `picklers-test-1` | Complete verification suite | Exited (0) after verification |

Application images are `picklers-app:production`, `picklers-app:development` and `picklers-app:test`. MySQL uses `mysql:8.4`. Six persistent volumes use the `picklers_` prefix; the project network is `picklers_default`. Apache and PHP belong in the same app container for this image. This application does not require separate Nginx, Redis, queue or Node server containers.

Both database services use the official MySQL server image. Doctrine uses the MySQL 8.4 platform. The application database volume is `picklers_mysql_database`. MySQL enables `log_bin_trust_function_creators` so the schema-scoped application user can install the audit-protection triggers without global `SUPER` privileges; see [MySQL's trigger and binary logging guidance](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html).

When switching an existing MariaDB deployment, export/import its application schema and data into a new MySQL volume; never mount MariaDB's data directory directly in MySQL. This workstation's migration retained the old `picklers_database` volume and saved the private export under `var/mysql-migration/`. All 31 table row counts and fingerprints matched after import. Existing demo/development MariaDB volumes also remain untouched; migrate them separately before using their previous data with the MySQL configuration.

To create all five named containers and retain the test result in Docker Desktop:

```sh
docker compose --env-file .env.docker --profile test build
docker compose --env-file .env.docker --profile test up --wait app test-database
docker compose --env-file .env.docker --profile test up --detach --no-deps test
docker wait picklers-test-1
docker compose --env-file .env.docker --profile test logs test
docker compose --env-file .env.docker --profile test ps --all
```

The detached command retains the named runner; `docker wait` prints its exit code after it finishes (zero means success). The application and databases stay running. Setup and test are jobs, so successful completion means stopped with exit code zero. Run verification serially; Compose's `up --exit-code-from` can stop a running test if another container for the same service exits. Stop the disposable database when finished with `docker compose --env-file .env.docker --profile test stop test-database`. For routine testing without retaining the runner, use `run --rm` below. Choose `-p picklers-dev` or `-p picklers-demo` for separate environments, and assign each a different HTTP port.

## Develop

```sh
docker compose --env-file .env.docker -f compose.yaml -f compose.dev.yaml up --build --wait app
```

The development override uses the same persistent application database, enables Symfony debug and simulated payments, installs development dependencies and Node, and mounts source/config/templates/assets read-only for live edits. Database/user content remains in Docker volumes. Rebuild after changing Composer dependencies or image configuration. Switching back to production uses the first command above and clears the appropriate cache during setup. Existing production rows are retained; development mode can add demo data, so use a separate Compose project (`-p picklers-dev`) when you need an entirely separate development database.

## Verify inside Docker

```sh
docker compose --env-file .env.docker --profile test run --build --rm test
docker compose --env-file .env.docker --profile test run --no-deps --rm test php scripts/docker-smoke.php http://app:8080
```

Run the second command after starting the app. The first runs PHP/JavaScript syntax, frontend regression tests, Composer validation/platform/security checks, Symfony container lint in all environments, Twig lint, routes, production warmup, schema-bootstrap checks, the service suite and PHPUnit. Tests run serially against disposable databases on **a separate test database container**; they cannot resolve the application server through their configured database connection. The test database uses temporary storage and test-only credentials, and exposes no host port. The HTTP checks verify public routes, guest access restrictions, CSRF rejection, assets, private-path denial and upload execution denial through Apache.

For a short configuration check:

```sh
docker compose --env-file .env.docker --profile test run --build --rm test php scripts/verify.php --quick
```

## Accounts and existing data

For a classroom demonstration with synthetic accounts and bookings, use the separate demo project described in [the demonstration guide](docs/local-demo.md). Its database and volumes are independent of the normal application. The October 22 verification used port **8081** for the normal application because another project occupied 8080; the demo used **8082**. Both remain configurable through `DOCKER_HTTP_PORT`.

Register an account through the application, then grant the initial administrator through Symfony Console:

```sh
docker compose --env-file .env.docker exec app php bin/console picklers:admin:privilege bootstrap you@example.com --reason="Initial administrator"
```

`bootstrap` works only when no active administrator exists, refuses deactivated accounts, and audits the role/privilege change atomically. Later privilege grants use `grant` and require an existing active administrator; role changes use the admin console.

Docker starts with a new database. It does not import, mount or modify XAMPP's database, `.env`, legacy JSON records, uploaded documents or logos. To move existing records, obtain a database dump and matching file backups, restore into a separate Docker installation, and rehearse migrations before switching traffic. See [the existing upgrade/rollback guide](docs/admin-migration/setup-and-rollback.md) for the migration history and preservation requirements. Do not run the legacy rollback rehearsal against an already-migrated source expecting legacy-schema assertions.

## Deployment limits

This Compose setup is ready for local development and container verification. A public deployment additionally needs the operator's domain/TLS termination, private secrets, backup/restore policy and host monitoring. The app currently has no real payment gateway: production top-ups fail closed. Docker does not supply that missing business integration or certify production capacity. Preserve the runtime secret volume, or configure a stable random `DOCKER_APP_SECRET` when moving deployments.
