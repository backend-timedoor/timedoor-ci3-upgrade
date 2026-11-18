// Runs once before the suite. Use it to create throwaway login accounts so the tests never need
// real passwords. Example for a CI app whose admins live in `user_adm(username, passwd=md5)`:
//
//   import { query } from './lib/db.js';
//   import { ROLES } from './lib/env.js';
//   const a = ROLES.admin;
//   await query('DELETE FROM user_adm WHERE username = ?', [a.username]);
//   await query('INSERT INTO user_adm (username, passwd) VALUES (?, MD5(?))', [a.username, a.password]);
//
// Match the project's real password hashing (md5, sha1, password_hash via PHP...). Never run against production.
// Remove the same rows in global-teardown.js.
export default async function globalSetup() {}
