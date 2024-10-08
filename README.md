# GangsterClub Online ALPHA v0.0.3

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/8e31139a6e204ca7ae111a45d2b04a7b)](https://app.codacy.com/gh/GangsterClub/GangsterClub/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade) [![Known Vulnerabilities](https://snyk.io/test/github/GangsterClub/GangsterClub/badge.svg)](https://snyk.io/test/github/GangsterClub/GangsterClub)

This is a boilerplate custom MVC framework in progress written in PHP.

## PHP Extensions

- yaml

## Composer dependencies

Execute command "composer install" from public_html, requires [composer installation](https://getcomposer.org/download/)

- twig/twig ^3.7.1
- voku/anti-xss ^4.1.42
- phpmailer/phpmailer ^6.8.1
- spomky-labs/otphp ^11.3.0

Optional depencency as defined by suggest:

- "symfony/yaml": "PHP's yaml_parse_file() fallback package"

Install fallback package if PHP's yaml extension is unavailable to you with command:

- composer require symfony/yaml

## Tailwindcss

Execute the following command from public_html, requires [tailwindcss installation](https://tailwindcss.com/docs/installation)

- tailwindcss -i web/css/tailwind.css -o web/cache/tailwind.css --minify
