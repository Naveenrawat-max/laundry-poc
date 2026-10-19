# Deploy to TransIP

Version: 0.1.0

How to put the mat tracking app on the TransIP web hosting, check that it works, update it later and roll it back. The short version is in the [README](../README.md#put-it-on-transip). The hosting design is in [ARCHITECTURE.md](../ARCHITECTURE.md#hosting-at-transip).

In every command, replace `mats.example.nl` with the real address, and `<ssh user>@<ssh host>` with the SSH login from the TransIP control panel.

| Part                          | Who                | Where                     |
| ----------------------------- | ------------------ | ------------------------- |
| Decide and check              | You                | The TransIP control panel |
| Merge, tag, build the web app | Developer          | Your own machine          |
| Install and set up            | You or a developer | SSH on the hosting        |
| Test it                       | You                | A browser and a terminal  |

## 1. Decide first

| Decision        | Recommended                                                                                  | Why                                                                                                                                             |
| --------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Address         | A subdomain, for example`mats.example.nl`                                                  | The login cookie works only at the address in`APP_URL`, so it must be fixed before the install                                                |
| Database        | A new database for the live site,**not** the test database                             | Every test run empties the database it points at. The live data must never sit there.                                                           |
| Data on day one | The mock data with 240 mats (`seed.sql` and `demo.sql`) for a demo; nothing for real use | Every mock mat has a tag id that starts with`4D4F434B`, so it can be removed before real mats come                                            |
| Simulator       | `SCANNER_SIMULATOR=true`                                                                   | Every handheld screen sends simulated scans. With`false` the server refuses them, and nothing can be scanned until the real C72 is connected. |
| Version         | `v0.5.8`, the version with the reminder, the swap by hand and the mat filter per place     | A tag fixes exactly what goes live and what to roll back to                                                                                     |

## 2. Check at TransIP

Do these checks before deploy day. Each one has a way around it, but some change the steps.

| Check                                                                                          | How                                                | If not                                                                                                           |
| ---------------------------------------------------------------------------------------------- | -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| SSH is on for the package                                                                      | Control panel, the package, SSH                    | Ask TransIP or change package. Without SSH there is no`php artisan`, and these steps do not work.              |
| PHP 8.3 or newer, for the site and on the command line                                         | Control panel, PHP version; and`php -v` over SSH | Pick 8.3 or 8.4 in the control panel. Both must be the same.                                                     |
| PHP extensions`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `ctype` | `php -m` over SSH                                | Switch them on in the control panel                                                                              |
| Composer                                                                                       | `composer --version` over SSH                    | Run`composer install --no-dev --optimize-autoloader` on your own machine and upload the folder `site/vendor` |
| Git                                                                                            | `git --version` over SSH                         | Download the release as a zip from GitHub and upload it                                                          |
| The web root can point at a subfolder                                                          | Control panel, the domain, web root (DocumentRoot) | Put the contents of`site/public` in the web root and fix the two paths in `index.php`. Ask a developer.      |
| HTTPS can be switched on                                                                       | Control panel, the domain, SSL                     | Required: the login cookie only travels over HTTPS                                                               |
| Database version                                                                               | phpMyAdmin:`SELECT VERSION();`                   | Needs MySQL 8.0.16+ or MariaDB 10.4+                                                                             |

## 3. On your own machine

The hosting has no Node, so the web app is built here and uploaded.

1. The full test suite is green on the branch.
2. Merge to `main`, push, then tag the version and push the tag:
   ```bash
   git checkout main && git pull
   git tag v0.5.8
   git push origin v0.5.8
   ```
3. Build the web app from exactly that tag:
   ```bash
   git checkout v0.5.8
   cd site
   npm ci
   npm run build          # writes site/public/build
   ```
4. Keep a copy of `site/public/build` named after the version, for example `build-0.5.8.zip`. A rollback needs it.

## 4. Deploy day

### 4.1 The database

1. Control panel, the domain, Site, Databases, "+ Add database". Name it, for example, `laundry_live`.
2. Three dots, Manage users, "+ Add user". Put the password in a password manager, nowhere else.
3. Click the database name and note the host name.

### 4.2 The code

Over SSH. The code goes in a folder that is **not** the web root, so `.env` and `storage` can never be opened from a browser. Go to the folder that holds the domain's web root folder (the control panel shows where it is), not into the web root itself.

```bash
ssh <ssh user>@<ssh host>
cd <the folder of the domain>
git clone https://github.com/Naveenrawat-max/laundry-poc.git laundry
cd laundry
git checkout v0.5.8
cd site
composer install --no-dev --optimize-autoloader
```

### 4.3 The settings

```bash
cp .env.example .env
nano .env
```

| Setting                                           | Value                                             |
| ------------------------------------------------- | ------------------------------------------------- |
| `APP_ENV`                                       | `production` (as in the example)                |
| `APP_DEBUG`                                     | `false` (as in the example)                     |
| `APP_URL`                                       | `https://mats.example.nl`                       |
| `DB_HOST`                                       | The host name from 4.1                            |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | From 4.1. The password between the double quotes. |
| `SCANNER_SIMULATOR`                             | `true`                                          |
| `SESSION_SECURE_COOKIE`                         | `true` (as in the example)                      |

Then make the app key, once:

```bash
php artisan key:generate
```

### 4.4 Tables, data and the first user

```bash
php artisan migrate --force                          # builds the tables from db/schema.sql
php artisan db:seed --force                          # demo only: 40 mock mats, 2 customers
php artisan db:seed --class=DemoSeeder --force       # demo only: 200 more mock mats, 10 more buildings
php artisan laundry:office-user                      # asks a name, a username and a password
```

Skip the two `db:seed` lines for real use. Every other user is made later on the Users page.

### 4.5 The web app files

From your own machine, in the repository folder:

```bash
scp -r site/public/build <ssh user>@<ssh host>:laundry/site/public/
```

On the server, `laundry/site/public/build/manifest.json` must now exist.

### 4.6 Folder rights and the settings cache

Over SSH, in `laundry/site`:

```bash
chmod -R u+rwX storage bootstrap/cache
php artisan config:cache
```

`config:cache` must run again after every change to `.env`. Until it does, the change has no effect.

### 4.7 The control panel

1. Point the web root (DocumentRoot) of `mats.example.nl` at `laundry/site/public`.
2. Switch HTTPS on.

## 5. Check that it works

### From a terminal

| Command                                                                                                                                                                                                                           | Must give                                                                                                                                                                                          |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `curl -sI http://mats.example.nl/`                                                                                                                                                                                              | `301` with `Location: https://mats.example.nl/`                                                                                                                                                |
| `curl -s -o /dev/null -w "%{http_code}\n" https://mats.example.nl/up`                                                                                                                                                           | `200`                                                                                                                                                                                            |
| `curl -s https://mats.example.nl/api/v1/auth/me -H "Accept: application/json"`                                                                                                                                                  | `401` and `{"error":{"code":...}}`                                                                                                                                                             |
| `curl -s https://mats.example.nl/.env \| grep -c APP_KEY`                                                                                                                                                                        | `0`. The address answers with the app's own page, which is fine. Anything above `0` means the web root is wrong and the settings file is readable: take the site offline and fix the web root. |
| `curl -s -o /dev/null -w "%{http_code} %{time_total}s\n" -X POST https://mats.example.nl/api/v1/auth/token -H "Accept: application/json" -H "Content-Type: application/json" -d "{\"username\":\"nobody\",\"password\":\"x\"}"` | `401`, and the time. Through the tunnel this took 2 seconds; on the hosting it should be well under one. Not measured yet.                                                                       |

### In the browser

| # | Do                                                                                                                                | Must see                                                             |
| - | --------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| 1 | Open`https://mats.example.nl` and log in with the office user of 4.4                                                            | The Workflow page and the menu                                       |
| 2 | Dashboard                                                                                                                         | With the demo data: 240 mats in inventory                            |
| 3 | Setup, Users: add one inventory manager, one driver and one washing house manager                                                 | They are in the list                                                 |
| 4 | Setup, Contracts: start one contract. Operations, Tasks: give a task to the driver.                                               | "Driver assigned."                                                   |
| 5 | Handheld: log in the three handhelds. Quality check one mat, hand it to the driver, load it on the van.                           | "1 of 1 moved" on both handhelds                                     |
| 6 | Driver: My tasks, open the stop, lay the mat, "Use a sample", sign, write a note (one mat is fewer than the contract asks), close | "Stop closed"                                                        |
| 7 | Tasks, Done, Proof                                                                                                                | The photo and the signature. This proves the server can store files. |

Something wrong: over SSH, `tail -n 50 ~/laundry/site/storage/logs/laravel.log` shows the error. The browser only shows a short message, because `APP_DEBUG` is `false`.

Steps 4 to 6 leave a closed stop and a planned swap in the data. For a clean demo, run 4.4 again on an empty database, or correct the mats in the mat list.

## 6. Rules after go-live

- Never run `php artisan test` or `php artisan migrate:fresh` on the server. Both empty the database.
- Never put the live database in `site/.env.testing` on a development machine, for the same reason.
- Before every update, export the database in phpMyAdmin. The proof photos and signatures live in `laundry/site/storage/app/private/proofs`; back that folder up too.
- `SCANNER_SIMULATOR` goes to `false` only when the real C72 sends the scans and the mock mats are gone.

## 7. Update to a new version

On your own machine: merge, tag (for example `v0.5.9`), push the tag, build, keep the build zip, as in step 3.

Over SSH, in `laundry`:

```bash
git fetch --tags
git checkout v0.5.9
cd site
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # only adds what is new; changes nothing that is there
php artisan config:cache
```

Then upload the new `site/public/build` as in 4.5, and run the checks of section 5.

## 8. Roll back

Over SSH, in `laundry`:

```bash
git checkout v0.5.8
cd site
composer install --no-dev --optimize-autoloader
php artisan config:cache
```

Upload the kept `build-0.5.8` folder as in 4.5. When the bad version changed the database, also restore the export made before the update in phpMyAdmin. Otherwise the database stays as it is.

## 9. Known limits on the hosting

| Limit                                                               | What it means                                                                                          |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Screens ask the server for news every few seconds; there is no push | A few handhelds and an office or two are fine. How many the package carries has not been measured.     |
| The reminder is inside the app only                                 | No email when a swap is due or late. Email would need mail settings and a scheduled job.               |
| No scheduled jobs                                                   | Nothing to set up in cron. Expired handheld logins stay in the table and are refused; they do no harm. |
| One installation serves every office user                           | Every office user sees every customer. A second client of the laundry needs its own installation.      |
