# GangsterClub Online ALPHA v0.0.5

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/d174a0a0c4e748569fc5f12c24b068d2)](https://app.codacy.com/gh/GangsterClub/GangsterClub/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade) [![Known Vulnerabilities](https://snyk.io/test/github/GangsterClub/GangsterClub/badge.svg)](https://snyk.io/test/github/GangsterClub/GangsterClub)

This is a boilerplate custom MVC framework in progress written in PHP.

## PHP Extensions

- yaml

## Composer dependencies

Execute command `composer install` from public_html, requires [composer installation](https://getcomposer.org/download/)

- twig/twig ^3.23.0
- phpmailer/phpmailer ^7.1.1
- spomky-labs/otphp ^11.5.0
- firebase/php-jwt ^7.1

Optional depencency as defined by suggest:

- `"symfony/yaml": "PHP's yaml_parse_file() fallback package"`

Install fallback package if PHP's yaml extension is unavailable to you with command:

- `composer require symfony/yaml`

## Tailwindcss

Execute the following command from public_html, requires [tailwindcss v4.2.2 installation](https://tailwindcss.com/docs/installation)

- `tailwindcss -i web/css/tailwind.css -o web/cache/tailwind.css --minify`

## Migration

Use migrations to create or update the database schema.

> **Warning:** The migration system attempts to preserve existing data, but schema changes may still result in data loss. Always create a full database backup before running a migration, performing a rollback, or moving the application to another server.

From the `public_html` directory, run one of the following commands:

### Migrate the database schema and import preserved data, if any

```bash
php run.php --migrate
```

### Roll back the last migration and preserve data

```bash
php run.php --rollback
```
