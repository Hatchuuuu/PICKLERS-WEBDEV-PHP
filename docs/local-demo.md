# Isolated classroom demonstration

Use Docker Desktop with Linux containers. Start from the normal README setup and a private `.env.docker` with two different random database passwords. This demo uses Symfony's development mode, simulated credits, a separate `picklers_e2e` database and separate named volumes. Never expose the demo publicly or use fixture credentials for real accounts.

Check that 8082 is free before starting. The verified Windows PowerShell commands are:

```powershell
$env:DOCKER_HTTP_PORT = '8082'
docker compose -p picklers-demo --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml config --quiet
docker compose -p picklers-demo --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml up --build --wait app
docker compose -p picklers-demo --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml exec app php scripts/seed-fixtures.php picklers_e2e
Remove-Item Env:DOCKER_HTTP_PORT
```

Open [the synthetic demo](http://localhost:8082). Every account below uses **Fixture-Pass-2026**, a fixture-only password:

| Role | Account |
| --- | --- |
| Player with credits and bookings | p1@fixture.test |
| Player with pending verification | p2@fixture.test |
| Owner of Fixture Arena Dumaguete | owner1@fixture.test |
| Owner of Fixture Courts Valencia | owner2@fixture.test |
| Administrator | admin@fixture.test |
| Privileged administrator | root@fixture.test |

The normal application does not receive these accounts. Re-seeding replaces synthetic fixture records and their generated dependent records in the demo database; it preserves unrelated rows. Use only this dedicated demo project for the walkthrough.

Suggested walkthrough: sign in as player, filter for Fixture Arena, inspect availability, request a future court using Pickle Credits, observe the pending booking and wallet debit; sign in as owner and approve; return as player and cancel at least 24 hours ahead; verify one full credit refund. Show owner earnings, staff and settings, then the administrator's protected console. Venue/manual payments do not generate platform payout funds. Withdrawable credit payments require authoritative booking-payment ledger evidence and a session whose scheduled end has passed; the existing 5% fee and 20% reserve still apply.

Production real-money top-ups are unavailable. GCash/Maya/Card selections are manual external payments; this demo does not certify a live gateway, SMS, social login, bank disbursement or password-recovery provider. Older credit payments without linked ledger metadata need reconciliation before they can fund payouts; no historical transactions are invented automatically.

For logs or shutdown (the environment variable is repeated because it is scoped to this shell):

```powershell
$env:DOCKER_HTTP_PORT = '8082'
docker compose -p picklers-demo --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml logs --tail=100 app setup database
docker compose -p picklers-demo --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml down
Remove-Item Env:DOCKER_HTTP_PORT
```

`down` retains the database and file volumes. Do not add `--volumes` to normal shutdown. The acceptance run used project name `picklers-acceptance-20261022` instead of `picklers-demo` to avoid existing installations; substitute that exact name to manage its retained test volumes. The guide's configuration and fixture commands were verified with that isolated project.
