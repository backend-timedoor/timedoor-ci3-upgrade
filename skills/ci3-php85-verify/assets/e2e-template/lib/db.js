import mysql from 'mysql2/promise';
import { DB } from './env.js';

// Small helper for global-setup/teardown and for looking up real ids to test with.
export async function query(sql, params = []) {
  if (!DB.database) throw new Error('Set E2E_DB_NAME (and E2E_DB_USER/PASS) to use the database helpers');
  const conn = await mysql.createConnection(DB);
  try {
    const [rows] = await conn.execute(sql, params);
    return rows;
  } finally {
    await conn.end();
  }
}
