# GangsterClub Online

> **Alpha** · v0.1.0

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
- JWT authentication and authorization (experimental, not yet used in production)
- TOTP / 2FA - Email and Authenticator app
- Recovery codes with mandatory acknowledgment during Authenticator app enrollment
- PHPMailer integration

## Quick Start

> **Warning:** Store generated secrets only in your local/deployment `.env` or secret manager. Do not put real secret values in `.env.example` or commit them to version control.

```bash
git clone https://github.com/GangsterClub/GangsterClub.git
cd GangsterClub/public_html

composer install
composer setup
tailwindcss -i web/css/tailwind.css -o web/cache/tailwind.css --minify

# Before continuing, configure DB_HOST, DB_NAME, DB_USER, and DB_PASS in .env.
php run.php --migrate
```

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

> **Warning:** The migration system attempts to preserve existing data by creating a JSON snapshot before dropping the current schema. Restoring snapshot data is best-effort and schema changes may still result in data loss. Always create a full database backup before running a migration, performing a rollback, or moving the application to another server.

Run migration commands from the `public_html` directory.

### Migrate the database schema

```bash
php run.php --migrate
```

Creates or updates the database schema and attempts to restore preserved snapshot data, if available.

### Roll back the database schema
```bash
php run.php --rollback
```
Creates a JSON snapshot of the current data and drops the migrated schema.

### Recommended update and migrate workflow

Update the application code before creating the rollback snapshot so that the rollback and migration use the same application version.

```bash
# Update the application code and resolve any Git conflicts before continuing.
git pull

composer install
php run.php --rollback && php run.php --migrate
```

> **TODO:** Refactor migrations to update the schema without dropping and rebuilding all migrated tables.

## Authenticator recovery

The application supports mandatory recovery-code acknowledgment during authenticator enrollment, recovery-code sign-in, self-service authenticator replacement, and browser-session revocation.

See [Authenticator recovery](docs/authenticator-recovery.md) for the full behavior, security constraints, and support policy.

## License

This project is licensed under the MIT License.
