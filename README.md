# Applications Import

Stage 1 provides a minimal Laravel 13 application with PHP 8.4, Apache, and MySQL 8.4 in Docker Compose. The homepage is available at [http://127.0.0.1:18081](http://127.0.0.1:18081). The XLSX import and application schema are future stages.

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
```

Open <http://127.0.0.1:18081>. MySQL is available to the app on the `db` service; it is not exposed on a host port. The HTTP PHP runtime has `max_execution_time=30` in `docker/php.ini`.

## Useful commands

```sh
docker compose exec -T app php artisan db:show --database=mysql
docker compose down
```

Do not run migrations yet: Stage 1 intentionally has no database tables. Keep the original assignment document and XLSX in `task/`; that directory, `.env`, and `vendor/` are ignored by Git.
