# Laundry RFID POC

Version: 0.3.1

RFID mat tracking for Proti. Proti rents door mats to its customers, delivers and collects them with its own drivers, and washes them in its own washing house. Every mat carries two RFID tags. This system follows each mat through inventory, delivery, the customer, collection and washing.

## Status

Design stage. The scope, the flow, the system design and the database are written. No application code exists yet.

The first build is a web app only. The Chainway C72 handheld is simulated by a scanner screen in the web app, and all mats are mock mats.

## Documents

| File | What it holds |
|---|---|
| [POC.md](POC.md) | Scope of the proof of concept |
| [FEATURES.md](FEATURES.md) | Feature list per role, and what the client told us |
| [FLOW.md](FLOW.md) | The mat lifecycle as a diagram |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System design: hosting, roles, data model, API, scanner simulator, security |
| [db/schema.sql](db/schema.sql) | Database schema, 13 tables |
| [db/seed.sql](db/seed.sql) | Mock data: 40 mats, 2 customers, 2 draft contracts |

Start with POC.md, then ARCHITECTURE.md.

## Planned stack

| Part | Built with |
|---|---|
| Frontend | React |
| Backend | PHP JSON API |
| Database | MySQL 8.0.16+ or MariaDB 10.4+ |
| Hosting | TransIP web hosting |
| Handheld (later) | Kotlin app on the Chainway C72 |

## Load the database

Create an empty database, then load the schema and the mock data, in that order:

```
mysql -u USER -p DBNAME < db/schema.sql
mysql -u USER -p DBNAME < db/seed.sql
```

Both files run unchanged on MySQL and MariaDB. At TransIP the same two files can be imported through phpMyAdmin.

The seed creates no users, because a password must not sit in a file. See "First login" in [ARCHITECTURE.md](ARCHITECTURE.md).

Every mock tag id starts with `4D4F434B` ("MOCK" in hex), so mock mats can be removed before real mats are registered.

## Versions

Each document carries its own version at the top. Every change bumps the version and is one commit.
