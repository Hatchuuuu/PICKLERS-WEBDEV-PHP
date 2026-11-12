# Symfony and Docker completion

The existing Symfony 7.4 kernel, attribute routes, dependency injection, Doctrine DBAL/migrations, Twig and Security remain the application architecture. PHP compatibility routes are Symfony routes, not separate legacy executables. Docker becomes the documented primary runtime; existing XAMPP state is preserved.

## Scope and acceptance

- Build a production PHP/Apache image from the Composer lockfile, with only `public/` exposed and a non-root web process.
- Run MariaDB on an internal Compose network. Persist database, private documents, uploads, state, sessions and generated secret separately from the image.
- Gate web startup on successful schema installation and Doctrine migrations; check actual HTTP readiness.
- Supply a development override with live source mounts and an isolated test profile with PHP, Composer and Node.
- Make supported verification/fixture commands use the shared environment bootstrap rather than require a host `.env` file.
- Verify fresh install, repeat setup, application HTTP routes/assets/access boundaries, production dependencies, full existing test gates and development startup inside Docker.
- Document commands, data migration boundaries and measured limits; refresh graphify after changes.

No rewrite of working domain services, no automatic transfer or replacement of XAMPP data, and no claim that tests certify every production scenario. Tests can drop only their disposable databases on the separate Docker test server.
