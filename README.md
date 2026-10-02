# Applications Import

This Laravel 13 project runs with PHP 8.4, Apache, and MySQL 8.4 in Docker Compose. It imports applications from an XLSX file through a single HTTP request. The import page is available at [http://127.0.0.1:18081](http://127.0.0.1:18081).

## Requirements

- Docker with Docker Compose
- Port 18081 available on localhost

Host PHP, Composer, Node, and npm are not required.

## First start

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec -T app composer install --no-interaction --no-progress
docker compose exec -T app php artisan key:generate --no-ansi
docker compose exec -T app php artisan migrate --force
```

The migration creates the `applications` table. Open <http://127.0.0.1:18081> to upload an XLSX file. MySQL is available to the app on the `db` service; it is not exposed on a host port. The HTTP PHP runtime has `max_execution_time=30`, `upload_max_filesize=20M`, and `post_max_size=24M` in `docker/php.ini`. Apache also rejects request bodies larger than 24 MiB with a clear 413 page before PHP starts.

## Useful commands

```sh
docker compose exec -T app php artisan db:show --database=mysql
docker compose down
```

Set `IMPORT_BATCH_SIZE` in `.env` to an integer from 1 to 1000. The default is 500. `IMPORT_MAX_UPLOAD_MIB` sets the application upload limit; its default is 20 MiB and must not exceed the PHP upload limit. After changing `docker/php.ini`, rebuild the app service with `docker compose up -d --build app`.

The file is read from PHP's temporary upload path. A successful import adds every row, including duplicate `external_id` values. Re-importing the same file adds those rows again. Reading, conversion, and all batch inserts run in one database transaction, so a failed import adds no rows. Keep the original assignment document and XLSX in `task/`; that directory, `.env`, and `vendor/` are ignored by Git.

## XLSX format

The workbook must contain exactly one worksheet. Its first row must have these 15 headers in this order: `external_id`, `created_at`, `first_name`, `last_name`, `phone`, `email`, `city`, `source`, `utm_campaign`, `product`, `budget_uah`, `status`, `manager`, `comment`, `next_contact_at`. Invalid rows stop the import and roll back all rows from that upload.

Dates retain the workbook's date and time without a timezone shift. Empty optional cells become `NULL`, while zero remains zero. A phone formula containing one plus sign followed by digits is stored as text with the plus sign; supported shared formulas use their base expression without calculation. Double-plus and other unusual phone formulas are stored with a leading `=` and counted as warnings. Numeric phone cells are converted to decimal text without inventing missing digits or a country code.

Worksheet rows are read sequentially, but shared strings are held in memory. Memory use therefore grows with the number and size of unique shared strings in the workbook; the reader does not have a fixed memory bound for that part.

## Verified local import

A multipart HTTP POST through Apache in the local macOS Docker Compose environment (PHP 8.4, MySQL 8.4, port 18081) with a valid CSRF session imported the supplied 11,098,384-byte XLSX. The POST took 6.035 seconds; reading, conversion, inserts, and commit inside the importer took 5.89 seconds. The application database count changed from 0 to 100,000. The result page showed 100,000 added rows and warning counts of 608 numeric phones, 205 unusual phone formulas, and 204 double-plus phone formulas. The effective HTTP PHP values were `max_execution_time=30`, `upload_max_filesize=20M`, and `post_max_size=24M`. This large-file measurement preceded the later error-handling refactor and was not repeated for it.

A separate HTTP request with a synthetic 25 MiB body returned status 413 and the upload-limit message. It did not import any data.

An earlier CLI run in the separate test database took 5.711 seconds and peaked at 41,947,136 bytes of memory. That CLI measurement does not establish the HTTP runtime limit or include multipart upload time.

## MySQL integration tests

The tests use a separate `applications_import_test` database on the same MySQL server. With the default `DB_USERNAME=applications`, create it once:

```sh
docker compose exec -T db sh -lc 'mysql -u root -p"$MYSQL_ROOT_PASSWORD"' <<'SQL'
CREATE DATABASE IF NOT EXISTS applications_import_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON applications_import_test.* TO 'applications'@'%';
SQL
docker compose exec -T app vendor/bin/phpunit
```

The integration tests verify the active database name before migrating or deleting test rows. They clear `applications` in the test database between cases; they do not clear the application database.
