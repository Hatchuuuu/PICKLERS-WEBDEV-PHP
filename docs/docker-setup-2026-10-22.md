# Docker setup verification — October 22, 2026

The local Compose project is `picklers`, served at http://localhost:8081 using the existing private `.env.docker`. Existing passwords and all six persistent volumes were retained. No unrelated project was changed, and no database or volume was deleted.

Updated to actual **MySQL Community Server 8.4.13**, using the official `mysql:8.4` image for both database services. The former MariaDB database was exported and imported into `picklers_mysql_database`; all 31 table row counts and row fingerprints matched. The original `picklers_database` MariaDB volume remains as a rollback copy, and private SQL exports/fingerprints are in the ignored `var/mysql-migration/` directory. Existing file volumes continue to be used.

## Names and responsibilities

| Container | Image | Expected result |
| --- | --- | --- |
| `picklers-app-1` | `picklers-app:production` | Healthy Apache/PHP/Symfony service |
| `picklers-database-1` | `mysql:8.4` | Healthy persistent application database |
| `picklers-setup-1` | `picklers-app:production` | Exit 0 after schema, migrations and cache initialization |
| `picklers-test-database-1` | `mysql:8.4` | Healthy disposable database, tmpfs storage |
| `picklers-test-1` | `picklers-app:test` | Exit 0 after the complete verification suite |

The development image is `picklers-app:development`; it was also built successfully. Production remains the active app image. Development/demo overrides passed Compose configuration validation; running a separate demo deployment is optional and its existing volumes remain available.

Names follow Compose's project/service/replica convention. Fixed `container_name` settings are intentionally unnecessary: they prevent scaling and cause collisions between environments. See [Docker's service reference](https://docs.docker.com/reference/compose-file/services/#container_name). There is no universal mandatory naming standard beyond Docker's supported syntax; these names provide a consistent, conventional scheme.

The project network is `picklers_default`. Persistent volumes are `picklers_mysql_database`, `picklers_runtime`, `picklers_documents`, `picklers_uploads`, `picklers_state` and `picklers_sessions`. Both database containers expose no host port. The app binds only `127.0.0.1:8081`, runs as `www-data`, and uses health checks. Startup waits for MySQL health and successful setup completion.

## Verification

- Production, development and test images built successfully.
- Base, development and demo Compose configurations validated.
- Application and both databases reached healthy status; setup exited 0.
- All eight Doctrine migrations applied, with zero pending or unavailable migrations.
- A separate fresh MySQL database installed all eight migrations using a schema-scoped, non-root account. MySQL's trigger-creation setting is configured consistently in both servers; the app user does not receive global `SUPER` privileges.
- The application-review concurrency regression checks now query MySQL Performance Schema lock tables on MySQL, while retaining their MariaDB query for native MariaDB installations. Both targeted tests passed with 38 assertions on MySQL.
- Host HTTP request to port 8081 returned 200.
- All 21 Apache HTTP checks passed: public routes/assets, guest restrictions, CSRF rejection, private files and uploaded executable denial.
- All 15 verification gates passed on MySQL; the retained `picklers-test-1` container exited 0. PHPUnit passed 287 tests with 2,304 assertions; the service suite passed 406 assertions. Composer audit reported no known vulnerability advisories.
- Existing named volumes were reattached to the rebuilt app/database containers.
- Graphify AST update completed successfully.

See [README](../README.md#container-and-image-names) for startup, retained test-container, logs, development and shutdown commands. Run full verification serially. Detached startup plus `docker wait picklers-test-1` retains an inspectable named result without Compose's abort-on-container-exit interaction with another runner for the same service.

## Limits

This is a local Docker setup. Public hosting, domain/TLS, backup scheduling and real payment-provider integration are outside this change. Setup and test are completed jobs, so exited containers with code zero are expected. Never use `down --volumes` unless intentionally deleting persistent application data.
