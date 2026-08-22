(() => {
  const composer = document.getElementById('composer');
  if (!composer) return;

  const cameraInput = document.getElementById('pick-camera');
  const filesInput = document.getElementById('pick-files');
  const queueEl = document.getElementById('queue');
  const sendBtn = document.getElementById('send-all');
  const statusEl = document.getElementById('send-status');
  const progressEl = document.querySelector('.progress');
  const progressBar = progressEl.querySelector('span');

  let targetPages = composer.querySelector('[data-pages]');

  function currentCard() {
    return composer.querySelector('.group-card[data-current]');
  }

  function pageCount(card) {
    return card.querySelectorAll('.page').length;
  }

  function readCard(card) {
    const files = [...card.querySelectorAll('.page')].map((el) => el._file).filter(Boolean);
    return {
      notes: card.querySelector('.group-notes').value.trim(),
      merge: card.querySelector('.merge-toggle').checked,
      files,
    };
  }

  function addPages(fileList) {
    const card = currentCard();
    const holder = card.querySelector('[data-pages]');
    [...fileList].forEach((file) => {
      const el = document.createElement('article');
      el.className = 'page';
      el._file = file;
      const isImage = file.type.startsWith('image/');
      el.innerHTML = `
        <div class="thumb">${isImage ? '' : '<span>PDF</span>'}</div>
        <div class="page-meta">
          <strong></strong>
          <em></em>
        </div>
        <div class="page-actions">
          <button type="button" class="icon" data-up title="Earlier">↑</button>
          <button type="button" class="icon" data-down title="Later">↓</button>
          <button type="button" class="icon danger" data-remove title="Remove">×</button>
        </div>`;
      el.querySelector('strong').textContent = file.name;
      el.querySelector('em').textContent = Math.max(1, Math.round(file.size / 1024)) + ' KB';
      if (isImage) {
        const img = document.createElement('img');
        img.alt = file.name;
        img.src = URL.createObjectURL(file);
        el.querySelector('.thumb').prepend(img);
      }
      holder.appendChild(el);
      renumber(card);
    });
  }

  function renumber(card) {
    card.querySelectorAll('.page').forEach((el, i) => {
      const kb = Math.max(1, Math.round(el._file.size / 1024));
      el.querySelector('em').textContent = 'Page ' + (i + 1) + ' · ' + kb + ' KB';
    });
  }

  composer.addEventListener('click', (e) => {
    const page = e.target.closest('.page');
    if (e.target.matches('[data-add-camera]')) {
      targetPages = currentCard().querySelector('[data-pages]');
      cameraInput.click();
    }
    if (e.target.matches('[data-add-files]')) {
      targetPages = currentCard().querySelector('[data-pages]');
      filesInput.click();
    }
    if (e.target.matches('[data-remove]') && page) {
      const img = page.querySelector('img');
      if (img?.src?.startsWith('blob:')) URL.revokeObjectURL(img.src);
      const card = page.closest('.group-card');
      page.remove();
      renumber(card);
    }
    if (e.target.matches('[data-up]') && page?.previousElementSibling) {
      page.parentElement.insertBefore(page, page.previousElementSibling);
      renumber(page.closest('.group-card'));
    }
    if (e.target.matches('[data-down]') && page?.nextElementSibling) {
      page.parentElement.insertBefore(page.nextElementSibling, page);
      renumber(page.closest('.group-card'));
    }
  });

  cameraInput.addEventListener('change', () => {
    addPages(cameraInput.files);
    cameraInput.value = '';
  });
  filesInput.addEventListener('change', () => {
    addPages(filesInput.files);
    filesInput.value = '';
  });

  document.getElementById('add-group').addEventListener('click', () => {
    const cur = currentCard();
    if (pageCount(cur) === 0 && !cur.querySelector('.group-notes').value.trim()) {
      cur.querySelector('.group-notes').focus();
      return;
    }
    cur.removeAttribute('data-current');
    const fresh = cur.cloneNode(true);
    fresh.setAttribute('data-current', '');
    fresh.querySelector('[data-pages]').innerHTML = '';
    fresh.querySelector('.group-notes').value = '';
    fresh.querySelector('.merge-toggle').checked = true;
    composer.insertBefore(fresh, cur.nextSibling);
    fresh.querySelector('.group-notes').focus();
    window.scrollTo({ top: fresh.offsetTop - 60, behavior: 'smooth' });
  });

  const drop = composer;
  drop.addEventListener('dragover', (e) => {
    e.preventDefault();
  });
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer?.files?.length) addPages(e.dataTransfer.files);
  });

  async function sendGroup(card, index, total) {
    const data = readCard(card);
    if (!data.files.length) return { ok: true, empty: true };
    const fd = new FormData();
    fd.append('notes', data.notes);
    fd.append('mode', data.merge && data.files.length > 1 ? 'merge' : 'separate');
    data.files.forEach((file) => fd.append('docs[]', file, file.name));
    const res = await fetch('upload.php?ajax=1', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    const json = await res.json();
    progressBar.style.width = Math.round(((index + 1) / total) * 100) + '%';
    return json;
  }

  sendBtn.addEventListener('click', async () => {
    const cards = [...composer.querySelectorAll('.group-card')];
    const ready = cards.filter((c) => pageCount(c) > 0);
    if (!ready.length) {
      statusEl.textContent = 'Add at least one photo or file.';
      return;
    }
    sendBtn.disabled = true;
    progressEl.hidden = false;
    progressBar.style.width = '4%';
    statusEl.textContent = 'Uploading…';
    let saved = 0;
    const problems = [];
    try {
      for (let i = 0; i < ready.length; i++) {
        statusEl.textContent = 'Uploading document ' + (i + 1) + ' of ' + ready.length + '…';
        const json = await sendGroup(ready[i], i, ready.length);
        if (json.documents) saved += json.documents.length;
        (json.errors || []).forEach((e) => problems.push(e));
        (json.skipped || []).forEach((e) => problems.push(e));
      }
      progressBar.style.width = '100%';
      if (saved && !problems.length) {
        window.location.href = 'index.php';
        return;
      }
      statusEl.textContent = saved ? 'Saved ' + saved + ' document(s). ' + problems.join(' ') : problems.join(' ') || 'Nothing saved.';
    } catch (err) {
      statusEl.textContent = 'Upload failed. Try fewer or smaller pages. ' + err.message;
    } finally {
      sendBtn.disabled = false;
    }
  });
})();
