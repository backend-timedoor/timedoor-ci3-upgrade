# Bundled CodeIgniter 3.1.13 system/ (pre-patched)

- Source: https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/3.1.13.zip
  (sha256 of that zip when bundled: 2e55fa5e1fc4bb22f2ea12058294eaff5defd74347806ecaa4bd568094755778)
- Only `system/` is included, plus the MIT `license.txt`.
- Already patched with `../../scripts/patch_ci_system.php` (PHP 8.2-8.5 fixes). Running the script on it
  again reports "changed 0". Verify with: `php scripts/patch_ci_system.php assets/codeigniter-3.1.13/system --check`
- Nothing else was changed. Line endings are as in the upstream zip.
