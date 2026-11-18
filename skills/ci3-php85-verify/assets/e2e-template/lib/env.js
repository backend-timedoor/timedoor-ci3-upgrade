// Project settings for the e2e suite. Everything can be overridden with environment variables,
// so the same files work on any machine (local stack, Docker, CI).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '../..');

export const BASE_URL = process.env.E2E_BASE_URL || 'http://localhost:8080';
// CodeIgniter's log folder (application/logs unless config.php log_path says otherwise)
export const LOG_DIR = process.env.E2E_LOG_DIR || path.join(projectRoot, 'application/logs');

export const DB = {
  host: process.env.E2E_DB_HOST || '127.0.0.1',
  port: Number(process.env.E2E_DB_PORT || 3306),
  user: process.env.E2E_DB_USER || 'root',
  password: process.env.E2E_DB_PASS || '',
  database: process.env.E2E_DB_NAME || '',
};

// One entry per login type (admin, member, ...). pages.json and downloads.json refer to these names.
// Credentials come from env vars or from global-setup.js (which can create a throwaway user).
export const ROLES = {
  admin: {
    loginUrl: '/admin/login',
    fields: { username: 'input[name=username]', password: 'input[name=password]' },
    submit: 'button[type=submit]',
    // a URL pattern that is only reachable after a successful login
    success: /\/admin\/?$/i,
    username: process.env.E2E_ADMIN_USER || 'e2e_admin',
    password: process.env.E2E_ADMIN_PASS || 'e2e-Secret-123',
  },
};

export const authFile = (role) => path.join(here, '..', '.auth', `${role}.json`);

export function readJson(name) {
  const file = path.join(here, '..', name);
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : [];
}
