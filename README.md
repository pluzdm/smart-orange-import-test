# Applications Import

This Laravel 13 project runs with PHP 8.4, Apache, and MySQL 8.4 in Docker Compose. It includes the applications schema and a transactional XLSX batch importer. The upload page is not implemented yet. The default homepage is available at [http://127.0.0.1:18081](http://127.0.0.1:18081).

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

Open <http://127.0.0.1:18081>. MySQL is available to the app on the `db` service; it is not exposed on a host port. The HTTP PHP runtime has `max_execution_time=30` in `docker/php.ini`.

## Useful commands

```sh
docker compose exec -T app php artisan db:show --database=mysql
docker compose down
```

Set `IMPORT_BATCH_SIZE` in `.env` to an integer from 1 to 1000. The default is 500. Keep the original assignment document and XLSX in `task/`; that directory, `.env`, and `vendor/` are ignored by Git.

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
