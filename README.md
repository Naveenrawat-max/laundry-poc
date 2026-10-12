# Laundry RFID POC

Version: 0.3.2

RFID mat tracking for Proti. Proti rents door mats to its customers, delivers and collects them with its own drivers, and washes them in its own washing house. Every mat carries two RFID tags. This system follows each mat through inventory, delivery, the customer, collection and washing.

## How it helps a laundry

A rental laundry owns thousands of items that all look the same and are always on the move: on a shelf, in a van, at a customer, in a washing machine. Without tracking, nobody can say where one mat is, how often it has been washed, or who had it last. Proti has no software for this today.

With an RFID tag in every mat, a handheld scanner reads a whole pile at once, without unfolding or counting by hand. This system turns those reads into answers.

| Problem in a laundry | What the system does |
|---|---|
| Nobody knows where a mat is | Every mat has one current status and place: inventory, a named driver, a customer's zone, or washing |
| Counting piles by hand is slow and wrong | One scan reads the whole pile and compares it with the list that was expected: found, missing, or not on the list |
| Mats go missing between people | A mat changes hands only when both the giver and the receiver have scanned it. A mat scanned by one side stays with the giver and is flagged for the office |
| Swaps are forgotten or late | The contract sets the days between swaps. When a delivery is done, the next swap task is created with its due date |
| A customer says "we never got them" | Every delivery, swap and collection is closed with a scan, a photo and a signature |
| Nobody knows how worn a mat is | Each mat keeps its wash count and its last two wash dates. The count goes up once per wash, even if the pile is scanned twice |
| A dirty or damaged mat goes out to a customer | A mat must pass a quality check before it can be handed to a driver. A failed mat goes to rewash, repair or scrap |
| Mistakes cannot be traced | Every scan is kept in the mat's history: who, when, where, and what changed |
| The office works from memory and paper | One web app holds customers, buildings, contracts, mats, tasks and users |

It is built for growth: 40 to 50 mats now, 4,000 to 5,000 in a few years, and rented workwear later on the same platform.

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
