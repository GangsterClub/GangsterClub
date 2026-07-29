# GangsterClub Online

> **Alpha** · v0.0.5

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/c9f86499481244ff9269ab82373c7361)](https://app.codacy.com/gh/GangsterClub/GangsterClub/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade) [![Known Vulnerabilities](https://snyk.io/test/github/GangsterClub/GangsterClub/badge.svg)](https://snyk.io/test/github/GangsterClub/GangsterClub)

A custom PHP MVC framework for building web applications.

## Features

- Custom PHP MVC architecture
- Dependency injection container
- Twig template engine
- YAML configuration
- Routing
- CLI commands
- Database migrations
- Authentication and session management
- JWT authentication
- TOTP / 2FA
- PHPMailer integration

## Quick Start

> **Warning:** Replace the default `JWT_SECRET` in `.env` with a new random, cryptographically secure value before deploying to production.

```bash
git clone https://github.com/GangsterClub/GangsterClub.git
cd GangsterClub/public_html

cp .env.example .env   # or copy on Windows

composer install
tailwindcss -i web/css/tailwind.css -o web/cache/tailwind.css --minify

# Generate three independent security peppers (run this command three times)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# Configure your database connection and paste one different generated value
# into each of AUTH_CHALLENGE_PEPPER, AUTH_RATE_LIMIT_PEPPER, and
# RECOVERY_CODE_PEPPER in .env
php run.php --migrate
```

Store generated secrets only in your local/deployment `.env` or secret manager. Do not put real secret values in `.env.example` or commit them to version control.

> **Note:** Configure your web server's document root to point to the `public_html` directory.

## Requirements

- Apache 2.4 or later
- PHP 8.2 or later
- MySQL 8.0 or later, or MariaDB 10.0 or later
- Composer
- Tailwind CSS

## PHP Extensions

- `yaml`

If the `yaml` extension is unavailable, install the optional Composer fallback package:

```bash
composer require symfony/yaml
```

## Composer

The project dependencies are managed with Composer. The `composer install` command shown in the Quick Start installs all required packages.

## Tailwind CSS

The project is tested with **Tailwind CSS v4.2.2**. Compile the CSS using the command shown in the Quick Start.

## Migration

Use migrations to create or update the database schema. A valid database connection configured in `.env` is required.

> **Warning:** The migration system attempts to preserve existing data, but schema changes may still result in data loss. Always create a full database backup before running a migration, performing a rollback, or moving the application to another server.

From the `public_html` directory, run one of the following commands:

### Migrate the database schema

```bash
php run.php --migrate
```

Creates or updates the database schema and imports preserved data, if any.

### Roll back the last migration

```bash
php run.php --rollback
```

Reverts the last migration and attempts to preserve existing data.

## License

This project is licensed under the MIT License.
