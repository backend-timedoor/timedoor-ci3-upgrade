# docs/PHP85_UPGRADE.md template

Write it for the developer who deploys and for the next one who maintains the project. Plain prose and tables.
Fill every section from the actual diff and test results, not from memory or from this template's examples.

```
# PHP 8.5 upgrade

One paragraph: from which PHP and CI version, branch, tested on PHP <8.5.x> (and still runs on <old PHP>),
minimum PHP and why (for example `?array` needs 7.1, Dompdf 3 needs 7.1).

## 1. Server deployment checklist (in order)
1. Back up: code folder (including git-ignored config and uploads) and a database dump.
2. Check PHP 8.5 extensions: mysqli (or pdo_mysql), mbstring, json, ctype, gd, dom/xml (Dompdf), curl,
   openssl, fileinfo, intl if used, zip if used. List only what this project needs.
3. Deploy the code. Removed files must be deleted on the server (old library folders, old system/ files):
   list them, because copying a zip over the old folder does not delete anything.
4. Writable folders: application/logs, application/cache, session save path, upload folders,
   <Dompdf font folder if any>.
5. Server-only config changes (git-ignored files): exact edits, or "none".
6. Switch the site to PHP 8.5, restart PHP-FPM/web server (clears OPcache).
7. Smoke test: the URLs in section 6, admin login, one PDF/export, one email.
8. Rollback: switch PHP back (the code still runs on <old PHP>); if needed restore the code backup.

## 2. What changed in the code
2.1 Framework: CodeIgniter <old> -> 3.1.13 + local PHP 8.2-8.5 patch (list the patch, how to re-apply).
2.2 Libraries (table: library | old | new | why | visible differences).
2.3 Application fixes (table: area | file | change | PHP version that required it).

## 3. Behaviour changes users may notice
For example: everyone is logged out once after the PHP switch (session ID length differs between PHP versions);
PDF reports look slightly different (fonts/weights); <page> now shows <x> where it used to fail silently.

## 4. Known issues not fixed (pre-existing)
Table: issue | where | impact | why not fixed now. Security exposures first.

## 5. Requirements
PHP range, extensions, web server modules (mod_rewrite), writable paths.

## 6. How it was tested
Tools and PHP versions, number of URLs and results, e2e suite (how to run it), what was tested by hand,
what was NOT tested and needs a person (webcam, payment gateway, real email, production data volume).
```

Also update the README (or the project's dev/agent notes): PHP version line, "system/ is CI 3.1.13 + PHP 8.5 patch",
and a link to this document.
