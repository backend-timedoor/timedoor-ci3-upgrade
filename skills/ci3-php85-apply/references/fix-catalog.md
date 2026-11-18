# CodeIgniter 3 PHP 8.5 fix catalog

Each entry: what breaks, which PHP version, the fix, and how it was found. Paths assume the default
`system/` and `application/` folders; use the real ones from `index.php` (`$system_path`, `$application_folder`).

## Contents
1. system/ (the framework)
2. Front controller (index.php)
3. Sessions
4. Application code
5. Bundled libraries (Dompdf, PHPMailer, others)
6. Things that look like upgrade bugs but are not

---

## 1. system/

### 1.1 Upgrade to stock 3.1.13 first
CI 3.1.11 and older do not run on PHP 8.1+: the session handlers miss return types (3.1.12 added
`PHP8SessionWrapper`), and several core files use removed behaviour. 3.1.13 is the last CI 3 release.

1. **Was system/ edited locally?** Download the stock zip of the project's *current* version
   (`https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/<version>.zip`) and diff its `system/`
   against the project's. Any local edit must be ported, ideally moved into an `application/core/MY_*`
   class so the next upgrade does not lose it. Tell the user what you found.
2. **Replace, do not merge.** Delete the old `system/` contents, then copy the new folder, so files removed
   upstream disappear. Copy dotfiles too: stock `system/.htaccess` is "Deny from all".
   The bundled `assets/codeigniter-3.1.13/system` is stock 3.1.13 with the patch below already applied.
3. **Read the upgrade notes** for every step between the old and new version
   (https://codeigniter.com/userguide3/installation/upgrading.html). For 3.1.x to 3.1.13 they are small,
   and mostly "replace system/". Apply the ones that touch `application/` (config keys, renamed settings).

### 1.2 The PHP 8.2-8.5 patch (`scripts/patch_ci_system.php`)
Stock 3.1.13 still logs deprecations on 8.2+, and some become errors in PHP 9. The script applies:

| Change | Why |
|---|---|
| `#[AllowDynamicProperties]` on `CI_Controller`, `CI_Loader`, `CI_Model`, `CI_Router`, `CI_URI`, `CI_DB_driver`, `CI_Table`, `CI_Driver_Library`, `CI_Profiler` | CI assigns libraries/models/config to undeclared properties (8.2 deprecation). The attribute is inherited, so app controllers/models are covered. `CI_Driver_Library` covers the Cache library, `CI_Profiler` its `$_compile_*` flags |
| `core/Exceptions.php`: key `E_STRICT` -> `2048` | `E_STRICT` constant deprecated in 8.4 |
| `libraries/Session/Session.php`: skip `ini_set('session.sid_length')` on 8.4+ | ini setting deprecated in 8.4 (see 3. Sessions) |
| `libraries/Encryption.php`: `?array $params = NULL` | implicit nullable deprecated in 8.4 |
| `libraries/Xmlrpcs.php`: `"multicall_{$err}"` | `${var}` interpolation deprecated in 8.2 |

All edits are harmless on PHP 7.x (`#[...]` is a comment there; `?array` needs 7.1+).
Document the patch in the project (README or AGENTS.md) so whoever replaces `system/` next re-applies it.

## 2. Front controller (index.php)
Remove `& ~E_STRICT` from both `error_reporting()` calls (8.4 deprecation; the constant still works
but logs). `patch_ci_system.php --index=index.php` does it. Keep the project's own `index.php`
(environment, folder paths): do not copy the stock one over it.

## 3. Sessions
- **Session ID length changes with the PHP version.** CI forces >= 160-bit IDs (40 hex chars) on PHP 7.1-8.3.
  On 8.4+ the patch leaves PHP's default (32 chars). Cookies with the old length fail CI's ID check, so
  **every user is logged out once** when the server switches PHP version. Put it in the deploy notes.
- `database` driver: the `id` column must hold the longest ID (VARCHAR(128) is safe).
- `files` driver: `sess_save_path` must be an absolute, writable path. `NULL` falls back to PHP's
  `session.save_path`, which differs per server.

## 4. Application code
Fix the root cause in the shared helper or model once, after grepping every caller.
Keep behaviour identical unless the user agrees otherwise; note every visible change for the docs.

### 4.1 Division or modulo by zero (8.0: `DivisionByZeroError`, fatal)
PHP 7 returned `INF`/`NAN` (or `false` for `%`) with a warning, and reports carried on. On 8 the page dies.
Common cause: averages and percentages over answers/rows that can be empty.
```php
// before
$pct = round(($wrong / $total) * 125, 2);
// after: keep the PHP 7 result, so reports and stored scores do not change
$pct = ($total == 0) ? ($wrong == 0 ? NAN : INF) : round(($wrong / $total) * 125, 2);
```
Returning `0` instead changes reports. Ask the user which they want, and say so in the docs.

### 4.2 `count()` on null or scalars (8.0: `TypeError`)
Fix the source that returns null (a model returning `false`/`null` instead of `array()`), or
`count((array) $x)` where null genuinely means empty. Do not wrap in `@`.

### 4.3 Null passed to string functions (8.1 deprecation)
`$this->input->post('x')` returns `NULL` when the field is missing: `trim()`, `strlen()`,
`htmlspecialchars()`, `explode()` then log deprecations. Use `(string) $this->input->post('x')` or `?? ''`.

### 4.4 Undefined variables and keys (8.0: Notice -> Warning)
Same behaviour, but now logged at the default `log_threshold`. Fix where the value matters. Typical CI
case: a view or modal included from several controllers, and only some controllers pass its variables
(fix: pass the variables from every controller that loads the view).

### 4.5 Dynamic properties (8.2 deprecation, Error in 9.0)
App classes extending `CI_Controller`/`CI_Model` are covered by the system patch. Other classes
(plain libraries, helpers' classes, result objects) need declared properties, or
`#[AllowDynamicProperties]` when the class is a bag of runtime fields. Classes used with
`$query->result('ClassName')` / `custom_result_object()` get one property per column: declare them.

### 4.6 Removed functions and syntax (8.0, fatal)
`each()` -> `foreach`; `create_function()` -> closure; `money_format()` -> `number_format()`;
`implode($array, $glue)` -> `implode($glue, $array)`; `get_magic_quotes_*()` -> remove;
nested ternary without parentheses -> add parentheses; `$str{0}` -> `$str[0]`; `(real)` -> `(float)`.

### 4.7 Deprecations 8.1-8.5
- `E_STRICT` (8.4), `trigger_error(..., E_USER_ERROR)` (8.4: throw an exception instead).
- Implicit nullable `Type $x = null` -> `?Type $x = null` (8.4; needs PHP 7.1+).
- `"${var}"` -> `"{$var}"` (8.2). `utf8_encode/decode` -> `mb_convert_encoding` (8.2).
- `strftime()` -> `date()`/`IntlDateFormatter` (8.1). `FILTER_SANITIZE_STRING` -> `htmlspecialchars` (8.1).
- `imagedestroy()` is a no-op since 8.0 and deprecated in 8.5: remove the call.
- Optional parameter before required (8.0): make it required or reorder (check callers).

### 4.8 Exceptions
`catch (Exception $e)` does not catch `TypeError`, `ValueError`, `DivisionByZeroError`. Where the code
means "anything went wrong" (an API call wrapper, a cron job), catch `Throwable`.

### 4.9 Output after a streamed file (found while testing PDFs)
Controllers that stream a PDF or file and then keep going (`header()` again, `print`, or
`$this->load->view()` after `$dompdf->stream()`) append junk after the file and log
"Cannot modify header information - headers already sent". Browsers often hide it; strict HTTP clients
reject the response. Stop after streaming (`return;` or `exit;`).

### 4.10 Raw mysqli outside CI's DB layer (8.1)
`mysqli` throws exceptions by default since 8.1. CI's driver switches that off for itself, but
hand-written `mysqli_*` code in the app (or a bundled tool) may now throw where it used to return `false`.

## 5. Bundled libraries

### 5.1 Dompdf (0.6-1.x: fatal or broken on 8.1+)
Upgrade to 3.x. Keep it where the project loads it from (often its own folder with a `vendor/`):
```
composer require dompdf/dompdf:^3.1 --working-dir=<dompdf-folder>
composer config platform.php 7.4.33 --working-dir=<dompdf-folder>   # oldest PHP still in use
composer update --working-dir=<dompdf-folder>
```
The platform pin keeps the lock file installable on both the old and the new PHP, so the code can be
deployed before the server switches. Remove the old library files from git (`git rm -r`), add the new ones.
- API: `new Dompdf($options)`, `loadHtml`, `setPaper`, `render`, `stream`/`output` are unchanged from 0.8.
  Very old wrappers using `dompdf_config.inc.php` / `DOMPDF_*` constants must be rewritten to `Options`.
- `isRemoteEnabled` must be on for `http(s)` images and CSS. Local files may need `chroot`.
- **Rendering differences found in practice:** `font-weight: 600` (and other numeric weights without a
  matching font file) fall back to a serif font: use `bold`, or register the weight. A `position: fixed`
  footer needs `width: 100%` to stay centred. Compare old and new PDFs page by page.
- Remote fonts (for example Google Fonts in the CSS) are downloaded into `lib/fonts/` on first render:
  that folder must be writable on the server, and the cache files (`*.json`, downloaded `*.ttf/*.ufm`)
  belong in `.gitignore`, keeping `installed-fonts.dist.json`.

### 5.2 PHPMailer 5.x (fatal: `__autoload`)
Replace with 6.x (namespaced: `new \PHPMailer\PHPMailer\PHPMailer()`; require `Exception.php`,
`PHPMailer.php`, `SMTP.php`). PHPMailer 6 does not infer TLS from the port: for port 465 set
`SMTPSecure = PHPMailer::ENCRYPTION_SMTPS`, port 587 keeps working through `SMTPAutoTLS`.
Prove it with a real login against the SMTP server, not only a local mail catcher.

### 5.3 Others
| Library | Action |
|---|---|
| mPDF < 8 | upgrade to 8.x (API changed: `new \Mpdf\Mpdf([...])`) |
| TCPDF < 6.4 | upgrade (drop-in) |
| PHPExcel | abandoned; PhpSpreadsheet is a separate migration. Plan it with the user, do not mix into this upgrade |
| Composer root `vendor/` | `composer why-not php 8.5`, then update with a platform pin, commit the lock |
| Adminer, file managers, other tools in the web root | old builds fatal on 8; more importantly they are a security exposure. Report, propose removal |

## 6. Not upgrade bugs (check these before blaming PHP 8)
- **HTTP 500 right after restarting the local stack:** the database was not up yet. CI logs
  `Unable to connect to the database` then a fatal on `num_rows()` of `false`. Start MySQL, retry.
- **500 on one admin page only, "Table ... doesn't exist" in the log:** the local DB is missing
  migrations. Run the project's migration SQL in date order.
- Pre-existing warnings that only *look* new because PHP 8 raised notices to warnings: run the same page
  on the old PHP and compare the log.
