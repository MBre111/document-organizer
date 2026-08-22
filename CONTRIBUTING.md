# Contributing

## How to run

Follow the README setup. Point Apache at your clone. `config.php` is gitignored — copy `config.example.php`.

## What not to commit

- Anything under `storage/` except empty `.gitkeep` folders
- `config.php` (machine paths, passwords)
- Real names, case numbers, bills, or OCR of personal files in fixtures
- Zip packets and Drive exports

## Making a change

1. Branch from `main`: `git checkout -b feat/short-name`
2. Match existing PHP: procedural, `declare(strict_types=1)`, `h()` for HTML, schema changes in both `schema.sql` **and** `ensure_schema()` / `migrate_catalog_schema()` in `includes/bootstrap.php`
3. Keep the dark mobile-friendly CSS (`min-height: 44px` on taps)
4. Open the pages you touched in a browser (Today, a document, Untrusted, a case)
5. Open a pull request with what you changed and how you checked it

## Schema

MySQL here may be MyISAM. Do not add foreign keys. Add columns through `includes/bootstrap.php` so existing installs migrate on the next page load.

## Product rules

- Inbox → extracted → confirmed. Do not invent amounts or case numbers.
- Untrusted facts: a short **question** plus 2–4 tap options. The UI always has type-your-own and None of these.
- 24-hour landlord *entry* notices are deadline kind `entry`, not `vacate`.
