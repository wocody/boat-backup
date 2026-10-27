/**
 * End-to-end migration on DISPOSABLE sites only.
 * Requires Playwright (or BOAT_BACKUP_PLAYWRIGHT_MODULE pointing to its index.mjs).
 * All screenshots/downloads stay in a random system temporary directory.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

if (process.env.BOAT_BACKUP_ALLOW_DISPOSABLE_RESTORE !== 'yes') {
  throw new Error('This replaces the destination site. Set BOAT_BACKUP_ALLOW_DISPOSABLE_RESTORE=yes only for disposable test installations.');
}
const required = (key) => {
  if (!process.env[key]) throw new Error(`Missing ${key}`);
  return process.env[key];
};
const sourceUrl = required('BOAT_BACKUP_SOURCE_URL');
const targetUrl = required('BOAT_BACKUP_TARGET_URL');
const sourceUser = required('BOAT_BACKUP_SOURCE_USER');
const sourcePassword = required('BOAT_BACKUP_SOURCE_PASSWORD');
const targetUser = required('BOAT_BACKUP_TARGET_USER');
const targetPassword = required('BOAT_BACKUP_TARGET_PASSWORD');
assert.notEqual(sourceUrl, targetUrl, 'Use two separate disposable installations');
const moduleName = process.env.BOAT_BACKUP_PLAYWRIGHT_MODULE;
const { chromium } = await import(moduleName ? pathToFileURL(moduleName).href : 'playwright');
const artifacts = await fs.mkdtemp(path.join(os.tmpdir(), 'boat-browser-'));
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const errors = [];
const coreNotes = [];
const observe = (page) => {
  page.on('pageerror', (error) => {
    if (error.message === 'Transition was aborted because of invalid state. ViewTransition opt-in disabled'
      || (error.name === 'AbortError' && error.message === 'Transition was skipped' && !error.stack)) coreNotes.push(error.message);
    else errors.push(error.message);
  });
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    // WordPress 7.x navigation can abort its own document view transition.
    // Keep the evidence separate; never discard arbitrary application errors.
    if (message.text() === 'Transition was aborted because of invalid state. ViewTransition opt-in disabled') coreNotes.push(message.text());
    else errors.push(message.text());
  });
};
async function login(page, base, user, password) {
  await page.goto(new URL('/wp-login.php', base).href, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
}
async function tools(page, base, tab = 'export') {
  const current = page.waitForResponse((response) => decodeURIComponent(response.url()).includes('/boat-backup/v1/current'));
  await page.goto(new URL(`/wp-admin/tools.php?page=boat-backup#boat-${tab}`, base).href, { waitUntil: 'domcontentloaded' });
  await current;
  await page.locator('h1').filter({ hasText: 'Boat Backup & Migration' }).waitFor();
  assert.match(await page.title(), /Boat Backup & Migration/);
  assert.match(await page.locator('.boat-backup').innerText(), /\.wpboat/);
  // A saved operation belongs to this test installation; cancel before a new run.
  if (await page.locator('#boat-cancel').isVisible()) {
    await page.locator('#boat-cancel').click();
    await page.locator('#boat-cancel').waitFor({ state: 'hidden' });
    const refreshed = page.waitForResponse((response) => decodeURIComponent(response.url()).includes('/boat-backup/v1/current'));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await refreshed;
  }
}
try {
  const source = await context.newPage();
  observe(source);
  await login(source, sourceUrl, sourceUser, sourcePassword);
  await tools(source, sourceUrl);
  await source.locator('#boat-export-start').click();
  await source.locator('#boat-result').waitFor({ state: 'visible', timeout: 180000 });
  await source.waitForFunction(() => !document.querySelector('#boat-export-start').disabled);
  assert.equal(await source.locator('#boat-progress').getAttribute('value'), '100');
  await source.screenshot({ path: path.join(artifacts, 'desktop.png'), fullPage: true });
  const downloading = source.waitForEvent('download');
  await source.locator('#boat-result').click();
  const download = await downloading;
  const backupPath = path.join(artifacts, 'backup.wpboat');
  await download.saveAs(backupPath);
  assert.equal(await download.failure(), null);
  await source.locator('[data-boat-tab="backups"]').click();
  await source.locator('#boat-backup-rows button').first().waitFor();
  await source.setViewportSize({ width: 390, height: 844 });
  assert.equal(await source.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Mobile horizontal overflow');
  assert.notEqual(await source.locator('.boat-backups thead').evaluate((node) => getComputedStyle(node).display), 'none', 'Headers must remain in the accessibility tree');
  assert.equal(await source.locator('.boat-backups th[scope="col"]').count(), 4);
  assert.ok(await source.locator('#boat-backup-rows td[headers="boat-backup-size"][data-label]').count());
  await source.screenshot({ path: path.join(artifacts, 'mobile.png'), fullPage: true });

  const target = await context.newPage();
  observe(target);
  await login(target, targetUrl, targetUser, targetPassword);
  await tools(target, targetUrl, 'import');
  assert.equal(await target.locator('[data-boat-engine]').count(), 0, 'Compatibility policy is automatic');
  const missingNonce = await context.request.get(new URL('/?rest_route=/boat-backup/v1/current', targetUrl).href);
  assert.ok([401, 403].includes(missingNonce.status()), 'Cookie alone must not authorize a REST operation');
  let uploads = 0;
  target.on('request', (request) => {
    if (request.method() === 'POST' && decodeURIComponent(request.url()).includes('/upload')) uploads++;
  });
  await target.locator('#boat-file').setInputFiles(backupPath);
  await target.locator('#boat-upload-start').click();
  await Promise.race([
    target.locator('#boat-confirm').waitFor({ state: 'visible', timeout: 180000 }),
    target.locator('#boat-error').waitFor({ state: 'visible', timeout: 180000 }).then(async () => { throw new Error(await target.locator('#boat-error').innerText()); }),
  ]);
  assert.ok(uploads > 1, 'Use a fixture larger than one upload chunk to catch numeric chunk-size regressions');
  await target.locator('#boat-database-report summary').click();
  const report = await target.locator('#boat-database-tables').innerText();
  assert.match(report, /InnoDB/);
  assert.match(report, /MyISAM/);
  assert.match(report, /MEMORY/);
  await target.screenshot({ path: path.join(artifacts, 'ready.png'), fullPage: true });
  await target.locator('#boat-acknowledge').check();
  await target.locator('#boat-confirm button').click();
  await target.locator('#boat-result').waitFor({ state: 'visible', timeout: 30000 });
  await target.locator('#boat-result').click();
  await target.locator('#user_login').waitFor();
  assert.deepEqual(errors, [], 'Unexpected browser errors');
  console.log(JSON.stringify({ result: 'PASS', sourceUrl, targetUrl, uploadRequests: uploads, bytes: (await fs.stat(backupPath)).size, coreNotes, artifacts }, null, 2));
} finally {
  await browser.close();
}
