# GangsterClub Online ALPHA v0.0.4

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/d174a0a0c4e748569fc5f12c24b068d2)](https://app.codacy.com/gh/GangsterClub/GangsterClub/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade) [![Known Vulnerabilities](https://snyk.io/test/github/GangsterClub/GangsterClub/badge.svg)](https://snyk.io/test/github/GangsterClub/GangsterClub)

This is a boilerplate custom MVC framework in progress written in PHP.

## PHP Extensions

- yaml

## Composer dependencies

Execute command "composer install" from public_html, requires [composer installation](https://getcomposer.org/download/)

- twig/twig ^3.7.1
- phpmailer/phpmailer ^6.8.1
- spomky-labs/otphp ^11.3.0
- firebase/php-jwt ^6.11

Optional depencency as defined by suggest:

- "symfony/yaml": "PHP's yaml_parse_file() fallback package"

Install fallback package if PHP's yaml extension is unavailable to you with command:

- composer require symfony/yaml

## Tailwindcss

Execute the following command from public_html, requires [tailwindcss installation](https://tailwindcss.com/docs/installation)

- tailwindcss -i web/css/tailwind.css -o web/cache/tailwind.css --minify
