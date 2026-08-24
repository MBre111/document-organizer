# Document organizer

A local PHP/MySQL cabinet for scans, bills, leases, and court papers.

Phone uploads land in an **inbox**. Cataloguing fills type, dates, tags, and people. Shaky fields wait as **untrusted facts** you tap to confirm. Confirmed files can be copied into a Drive folder tree. Cases have a timeline and a zip packet.

This repo is **code only**. Do not commit real documents, OCR dumps of personal files, or `config.php`.

## Requirements

- Windows with [WampServer](https://www.wampserver.com/) (Apache + PHP 8.3+ + MySQL)
- PHP extensions: `pdo_mysql`, `fileinfo`, `zip`
- Optional OCR: [Tesseract](https://github.com/UB-Mannheim/tesseract/wiki) and [Poppler](https://github.com/oschwartz10612/poppler-windows) (`pdftoppm`)
- Optional: Google Drive for Windows at `G:\My Drive`

## Setup

1. Clone into WAMP’s web root (or a vhost pointing here):

   ```bash
   git clone https://github.com/MBre111/document-organizer.git
   cd document-organizer
   ```

2. Copy config and create folders:

   ```bash
   copy config.example.php config.php
   mkdir storage\inbox storage\library
   ```

3. Edit `config.php` (`db_*`, `storage`, optional `tesseract` / `pdftoppm` / `drive_root`).

4. Create the database (MySQL root or your user):

   ```bash
   mysql -u root < schema.sql
   ```

   Or open `/install.php` once in the browser.

5. Browse to `http://localhost/organizer/` (or your vhost). **Today** is the daily pass.

## Layout

| Path | What |
|---|---|
| `index.php` | Today (morning log, coming up, facts) / inbox / library |
| `journal.php` | Daily journal; saving proposes tasks to tap |
| `deadline.php` | One task + checklist |
| `money.php` | Fintable balances/transactions + monthly bills |
| `upload.php` | Phone-friendly multi-page upload |
| `document.php` | Viewer, facts, related docs, edit |
| `facts.php` | Untrusted tap-to-confirm |
| `case.php` / `packet.php` | Case timeline and zip packet |
| `entity.php` | Person / place / org wiki page |
| `ocr.php` | Batch OCR of existing files |
| `includes/` | Schema migrator, catalog, ingest, cabinet |
| `schema.sql` | Tables (no foreign keys; MyISAM-safe) |

Uploads go to `storage/inbox/`. After **confirmed**, files move to `storage/library/{year}/` and may copy to Drive `00-Organizer/{year}/{type}/`.

## Collaborating

See [CONTRIBUTING.md](CONTRIBUTING.md). Issues and pull requests are welcome. Keep sample data fictional; never push real scans.

## License

MIT — see [LICENSE](LICENSE).
