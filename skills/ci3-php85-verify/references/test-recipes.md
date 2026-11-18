# Test recipes for a CodeIgniter 3 PHP 8.5 upgrade

Commands use `$PHP85` / `$PHPOLD` for full paths to the PHP binaries. Adapt shell syntax to the OS.

## 1. Read CodeIgniter's log, not the page
- Production mode (`ENVIRONMENT` = `production`) hides PHP errors: the page is blank, a generic 500, or looks fine.
- CI logs PHP warnings/notices/deprecations and uncaught exceptions as `ERROR - <time> --> Severity: ...` in
  `application/logs/log-YYYY-MM-DD.php` when `log_threshold` >= 1. The file date follows the server's timezone.
- Record each log file's size before a request and read only the new bytes after it (`smoke.php` and the
  e2e fixture do this). Run requests one at a time, or errors from parallel requests get mixed.
- `Severity: error --> Exception: ...` and `Query error` are hard failures. `Severity: Warning` lines need
  sorting (recipe 5).

## 2. Serving under PHP 8.5
1. Best: the user's own web server switched to 8.5 (vhost/pool/PHP selector), or Docker
   (`php:8.5-apache` with the project mounted and `a2enmod rewrite`). This matches production.
2. Built-in server: `cd <project-root> && $PHP85 -S 127.0.0.1:8085 <skill>/scripts/router.php`.
   - One request at a time: pages that call the same server while rendering hang (Dompdf with
     `isRemoteEnabled` loading the site's own CSS/images, `file_get_contents(base_url(...))`).
     Use `PHP_CLI_SERVER_WORKERS=4` (not on Windows), a second built-in server for `/assets`, or a real web server.
   - `base_url` in `config.php` may be hard-coded or built from the host: requests must use a host it accepts.
     If it redirects to HTTPS (`.htaccess` or code), test through the real stack or a matching URL.
3. Start servers on a known port, remember the PID, stop only that PID at the end.

## 3. Logged-in pages
- Preferred: create a throwaway account in the **local** database (match the app's password hashing:
  md5, sha1, `password_hash`), log in through the form, use the session cookie. Delete the account afterwards.
- Find the cookie name in `config.php` `sess_cookie_name`. The login form's field names are in its view.
- Fallback for the `files` session driver only (local machine, never on a shared server): create a session file
  in `sess_save_path` (or PHP's `session.save_path`) named `<cookie_name><id>` containing the keys the auth check
  reads, for example `logged_in|b:1;`. The ID must match CI's ID check: 32 lowercase hex chars on PHP 8.4+,
  40 on 7.1-8.3 with CI 3.1.13. Delete the file afterwards.

## 4. Route coverage
- `list_routes.php` reads controllers statically: every public method is a URL (`controller/method`, `index` drops off),
  sub-folders become prefixes. `config/routes.php` entries are listed at the end: add the ones that are the real
  public URLs (pretty URLs, `_remap`, `404_override`).
- `needs args`: look up a real id in the DB (`SELECT id FROM <table> ORDER BY id DESC LIMIT 1`). Prefer records
  that exercise the most code (a finished test, an order with many items, a report with every section).
- `WRITES?` is a name heuristic. Read the method if unsure. GET endpoints that write (logout, toggle,
  "refresh" jobs) must stay out of an automated crawl.
- DataTables/AJAX endpoints that read `$_POST` warn on a plain GET on every PHP version. Test them by
  loading the page that calls them (the e2e suite does this) or POST the same fields.

## 5. Sorting failures: new on 8.5 or pre-existing?
Run the same URL list on `$PHPOLD` and on `$PHP85`, then compare status codes and log lines.
- Only on 8.5 -> upgrade bug -> fix (back to `ci3-php85-apply`).
- On both -> pre-existing -> document; fix only if the user asks. Wording changes are normal
  ("Trying to access array offset on value of type null" vs "on null"; Notice on 7.4 vs Warning on 8.x): match by file:line.
- A fatal on 8.5 that was a warning on 7.4 is an upgrade bug even if the root cause is old
  (division by zero, `count(null)`).

## 6. Files and PDFs
- Check `Content-Type`, that a PDF starts with `%PDF-` and ends with `%%EOF` with nothing after it, and that it has pages.
  Extra bytes after `%%EOF` mean the controller printed something after streaming (fix-catalog 4.9).
- Render one PDF of every template on the old and new PHP and compare visually (open both, or convert pages to
  images with any PDF tool). Library upgrades change fonts, weights and positioned elements.
- For exports (CSV/Excel), open the file and compare row counts with the old output.

## 7. HTTP 500 that is not the upgrade
Check these before debugging PHP 8:
- Database down or still starting (log: `Unable to connect to the database`, then `num_rows() on false`).
- Local schema behind the code: `Query error: Table '<db>.<table>' doesn't exist`. Apply the project's migrations
  (CI migrations, or the SQL files the team keeps) to the local DB.
- Missing writable folders: `application/logs`, `application/cache`, the session path, upload folders, Dompdf's font cache.
- `.htaccess` rewrite not active (every URL except `/` gives 404) or forced-HTTPS redirect loops.

## 8. Mail
A local mail catcher (Mailpit, MailHog, smtp4dev) proves the message is built and sent, but it accepts anything.
Before deploy, also prove the real SMTP login works with the production settings (connect + AUTH only, send nothing),
especially after a PHPMailer 5 -> 6 upgrade (port 465 needs implicit TLS). Never email real recipients from tests.
