/* global boatBackupAdmin */
(() => {
  'use strict';
  const config = boatBackupAdmin;
  // wp_localize_script casts top-level scalar values to strings.
  const chunkSize = Math.max(1, Math.floor(Number(config.chunk)));
  const text = config.strings;
  const byId = (id) => document.getElementById(`boat-${id}`);
  let job = null;
  let running = false;
  let paused = false;
  let loop = null;
  let backupFile = null;

  const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

  const message = (value = '') => {
    byId('error').hidden = !value;
    byId('error').querySelector('p').textContent = value;
  };

  async function request(path, data, raw = false, method = 'POST') {
    const url = new URL(config.api);
    if (url.searchParams.has('rest_route')) {
      const [route, query] = path.split('?');
      url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
      if (query) new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
    } else {
      const [route, query] = path.split('?');
      url.pathname += route;
      if (query) url.search = query;
    }
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': config.nonce, ...(method !== 'GET' ? { 'Content-Type': raw ? 'application/octet-stream' : 'application/json' } : {}) },
      body: method === 'GET' ? undefined : (raw ? data : JSON.stringify(data || {})),
      cache: 'no-store',
    });
    const nonce = response.headers.get('X-WP-Nonce');
    if (nonce) config.nonce = nonce;
    let result;
    try { result = await response.json(); } catch (_) { throw new Error(text.network); }
    if (!response.ok) {
      const error = new Error(result.message || text.network);
      error.status = response.status;
      throw error;
    }
    return result;
  }

  function tab(name) {
    if (!['export', 'import', 'backups'].includes(name)) name = 'export';
    document.querySelectorAll('[data-boat-tab]').forEach((link) => {
      const selected = link.dataset.boatTab === name;
      link.classList.toggle('nav-tab-active', selected);
      if (selected) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
      byId(link.dataset.boatTab).hidden = !selected;
    });
  }

  function render() {
    byId('operation').hidden = !job;
    const active = job && !['completed', 'cancelled'].includes(job.phase);
    document.querySelectorAll('[data-boat-start]').forEach((button) => { button.disabled = !!active || running; });
    byId('upload-start').disabled = running || (!!active && job.phase !== 'upload');
    document.querySelectorAll('[data-boat-backup-action]').forEach((button) => { button.disabled = !!active || running; });
    if (!job) return;
    byId('progress').value = job.progress;
    byId('progress').setAttribute('aria-label', text[job.phase] || text.paused);
    byId('percent').textContent = `${job.progress}%`;
    byId('status').textContent = (paused || job.paused) ? text.paused : (job.status === 'failed' ? text.failed : (text[job.phase] || text.paused));
    byId('operation').setAttribute('aria-busy', String(running));
    byId('resume').hidden = !active || running || ['ready', 'password'].includes(job.phase);
    byId('pause').hidden = !active || !running || ['ready', 'applying'].includes(job.phase);
    byId('cancel').hidden = !active || job.phase === 'applying';
    byId('unlock').hidden = job.phase !== 'password';
    byId('unlock').querySelector('button').disabled = running;
    byId('confirm').hidden = job.phase !== 'ready';
    byId('confirm').querySelector('button').disabled = running;
    byId('confirm').querySelectorAll('[data-boat-database]').forEach((node) => { node.hidden = !!job.files_only; });
    byId('confirm').querySelector('[data-boat-files]').hidden = !job.files_only;
    byId('acknowledge-text').textContent = job.files_only ? text.ackFiles : text.ackDatabase;
    const restored = job.phase === 'completed' && job.kind !== 'export';
    byId('done').hidden = !restored;
    if (restored) {
      byId('permalinks').href = job.permalinks;
    }
    const result = byId('result');
    result.hidden = job.phase !== 'completed';
    if (job.phase === 'completed') {
      byId('status').textContent = job.kind === 'export' ? text.exportDone : (job.files_only ? text.completed : text.restoreDone);
      result.href = job.kind === 'export' ? job.download : job.login;
      result.textContent = job.kind === 'export' ? text.download : text.login;
      // A files-only restore keeps this site's users and session.
      result.hidden = job.kind !== 'export' && !!job.files_only;
    }
    if (job.error) message(job.error);
    const tables = job.database || [];
    byId('database-report').hidden = !tables.length;
    byId('engine-summary').textContent = text.compatibility;
    byId('database-tables').replaceChildren(...tables.map((table) => {
      const item = document.createElement('li');
      item.textContent = `${table.table}: ${table.source}${table.source !== table.target ? ` → ${table.target}` : ''}`;
      return item;
    }));
  }

  async function process() {
    if (running || !job) return;
    running = true;
    paused = false;
    message();
    render();
    try {
      while (!paused && !['ready', 'completed', 'cancelled', 'password'].includes(job.phase)) {
        if (job.phase === 'upload' && job.upload_offset < job.upload_size) {
          if (!backupFile) { tab('import'); throw new Error(text.fileRequired); }
          if (backupFile.size !== job.upload_size) throw new Error(text.fileMismatch);
          const end = Math.min(job.upload_offset + chunkSize, backupFile.size);
          job = await request(`jobs/${job.id}/upload?offset=${job.upload_offset}`, backupFile.slice(job.upload_offset, end), true);
        } else if (job.driver === 'server') {
          // The server continues on its own; this page only follows progress.
          await wait(2000);
          job = await request(`jobs/${job.id}`, undefined, false, 'GET');
        } else {
          try {
            job = await request(`jobs/${job.id}/step`);
          } catch (error) {
            // A server step still holds the lock; follow it instead of failing.
            if (error.status !== 409) throw error;
            await wait(2000);
            job = await request(`jobs/${job.id}`, undefined, false, 'GET');
          }
        }
        render();
      }
      if (job.phase === 'completed') await backups();
    } catch (error) {
      message(error.message || text.network);
    } finally {
      running = false;
      render();
    }
  }

  function run() { loop = process(); return loop; }

  async function start(kind, name = '', size = 0, options = {}) {
    message();
    job = await request('jobs', { kind, name, size, options });
    byId('acknowledge').checked = false;
    render();
    byId('operation').scrollIntoView({ block: 'nearest' });
    await run();
  }

  function safe(handler) {
    return async (event) => {
      event.preventDefault();
      try { await handler(event); } catch (error) { message(error.message || text.network); }
    };
  }

  async function backups() {
    const items = await request('backups', undefined, false, 'GET');
    const body = byId('backup-rows');
    body.replaceChildren();
    if (!items.length) {
      const cell = document.createElement('td');
      cell.colSpan = 4;
      cell.textContent = text.empty;
      const row = document.createElement('tr');
      row.append(cell);
      body.append(row);
    }
    for (const item of items) {
      const row = document.createElement('tr');
      for (const [index, value] of [item.label ? `${item.label} — ${item.name}` : item.name, new Date(item.created * 1000).toLocaleString(document.documentElement.lang || undefined), `${(item.size / 1024 / 1024).toFixed(2)} MiB`].entries()) {
        const cell = document.createElement('td');
        cell.setAttribute('headers', ['boat-backup-name', 'boat-backup-created', 'boat-backup-size'][index]);
        if (index > 0) cell.dataset.label = index === 1 ? text.created : text.size;
        cell.textContent = value;
        row.append(cell);
      }
      const actions = document.createElement('td');
      actions.setAttribute('headers', 'boat-backup-actions');
      const download = document.createElement('a');
      download.className = 'button';
      download.href = item.download;
      download.textContent = text.download;
      actions.append(download);
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'button';
      edit.textContent = text.editLabel;
      edit.setAttribute('aria-label', `${text.editLabel} ${item.name}`);
      edit.addEventListener('click', safe(async () => {
        const label = window.prompt(text.labelPrompt, item.label || '');
        if (label === null) return;
        await request(`backups/${encodeURIComponent(item.name)}/label`, { label });
        await backups();
      }));
      actions.append(edit);
      for (const action of ['restore', 'delete']) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.dataset.boatBackupAction = action;
        button.textContent = text[action];
        button.setAttribute('aria-label', `${text[action]} ${item.name}`);
        button.addEventListener('click', safe(async () => {
          if (action === 'restore') await start('restore', item.name);
          else if (window.confirm(text.deleteConfirm)) {
            await request(`backups/${encodeURIComponent(item.name)}`, undefined, false, 'DELETE');
            await backups();
          }
        }));
        actions.append(button);
      }
      row.append(actions);
      body.append(row);
    }
    render();
  }

  function exportOptions() {
    const options = { replace: [] };
    document.querySelectorAll('[data-boat-option]').forEach((input) => { options[input.name] = input.checked; });
    if (byId('protect').checked) {
      if (byId('password-new').value !== byId('password-repeat').value) throw new Error(text.mismatch);
      options.password = byId('password-new').value;
    }
    byId('replace-rows').querySelectorAll('li').forEach((row) => {
      const find = row.querySelector('[data-boat-find]').value;
      const replace = row.querySelector('[data-boat-replace]').value;
      if (find !== '' && replace !== '') options.replace.push({ find, replace });
    });
    return options;
  }

  function numberRows() {
    byId('replace-rows').querySelectorAll('li').forEach((row, index) => {
      row.querySelector('[data-boat-replace-remove]').setAttribute('aria-label', text.removeRow.replace('%d', index + 1));
    });
  }

  byId('replace-add').addEventListener('click', () => {
    const row = byId('replace-row').content.firstElementChild.cloneNode(true);
    row.querySelector('[data-boat-replace-remove]').addEventListener('click', () => {
      row.remove();
      numberRows();
      byId('replace-add').focus();
    });
    byId('replace-rows').append(row);
    numberRows();
    row.querySelector('[data-boat-find]').focus();
  });
  byId('export-form').addEventListener('submit', safe(() => start('export', '', 0, exportOptions())));
  byId('protect').addEventListener('change', () => {
    const on = byId('protect').checked;
    byId('protect-fields').hidden = !on;
    byId('protect').setAttribute('aria-expanded', String(on));
    byId('password-new').required = on;
    byId('password-repeat').required = on;
    if (on) byId('password-new').focus();
  });
  byId('unlock').addEventListener('submit', safe(async () => {
    const field = byId('password');
    job = await request(`jobs/${job.id}/password`, { password: field.value });
    field.value = '';
    message();
    render();
    await run();
  }));
  const drop = byId('drop');
  ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
    event.preventDefault();
    drop.classList.add('is-dragging');
  }));
  ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('is-dragging')));
  drop.addEventListener('drop', (event) => {
    event.preventDefault();
    if (!event.dataTransfer.files.length || byId('file').disabled) return;
    byId('file').files = event.dataTransfer.files;
    byId('upload-form').requestSubmit();
  });
  byId('upload-form').addEventListener('submit', safe(async () => {
    backupFile = byId('file').files[0];
    if (!backupFile) throw new Error(text.fileRequired);
    if (job && job.phase === 'upload') await run();
    else await start('import', backupFile.name, backupFile.size);
  }));
  byId('resume').addEventListener('click', safe(run));
  byId('pause').addEventListener('click', safe(async () => {
    paused = true;
    job = await request(`jobs/${job.id}/pause`);
    render();
  }));
  byId('cancel').addEventListener('click', safe(async () => {
    paused = true;
    if (loop) await loop;
    job = await request(`jobs/${job.id}/cancel`);
    message();
    render();
    await backups();
  }));
  byId('confirm').addEventListener('submit', safe(async () => {
    if (!byId('acknowledge').checked || running) return;
    running = true;
    const id = job.id;
    job.phase = 'applying';
    render();
    try {
      job = await request(`jobs/${id}/commit`, { confirm: true });
      message();
    } finally {
      running = false;
      render();
    }
  }));
  window.addEventListener('hashchange', () => tab(location.hash.replace('#boat-', '')));
  window.addEventListener('beforeunload', (event) => {
    if (running) { event.preventDefault(); event.returnValue = ''; }
  });
  tab(location.hash.replace('#boat-', ''));
  (async () => {
    try {
      job = (await request('current', undefined, false, 'GET')).job;
      render();
      await backups();
      // Reattach to an operation the server is still running.
      if (job && job.driver === 'server') await run();
    } catch (error) { message(error.message || text.network); }
  })();
})();
