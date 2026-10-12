// Catalogue filter + search. Everything works without JS via the category pages.
(function () {
  const grid = document.querySelector('.grid');
  const q = document.getElementById('q');
  if (!grid || !q) return;
  const cards = [...grid.querySelectorAll('.card')];
  const empty = document.querySelector('.empty');
  const FOLD = { 'İ': 'i', 'I': 'i', 'ı': 'i', 'Ş': 's', 'ş': 's', 'Ğ': 'g', 'ğ': 'g', 'Ç': 'c', 'ç': 'c', 'Ö': 'o', 'ö': 'o', 'Ü': 'u', 'ü': 'u' };
  const norm = s => String(s).replace(/[İIıŞşĞğÇçÖöÜü]/g, c => FOLD[c]).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const state = { text: '', status: '', cat: '' };
  let index = {};

  fetch('/search.json').then(r => r.json()).then(list => { index = Object.fromEntries(list.map(x => [x.s, x.t])); apply(); }).catch(() => {});

  function apply() {
    const words = norm(state.text).split(' ').filter(Boolean);
    let shown = 0;
    for (const c of cards) {
      const hay = index[c.dataset.s] || norm(c.textContent);
      const ok = (!state.status || c.dataset.status === state.status)
        && (!state.cat || c.dataset.cat === state.cat)
        && words.every(w => hay.includes(w));
      c.hidden = !ok;
      if (ok) shown++;
    }
    empty.hidden = shown !== 0;
  }

  q.addEventListener('input', () => { state.text = q.value; apply(); });
  document.querySelectorAll('.seg').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.seg').forEach(x => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-pressed', String(x === b)); });
    state.status = b.dataset.status; apply();
  }));
  document.querySelectorAll('.pill[data-cat]').forEach(p => p.addEventListener('click', e => {
    e.preventDefault();
    const on = !p.classList.contains('is-on');
    document.querySelectorAll('.pill').forEach(x => x.classList.remove('is-on'));
    p.classList.toggle('is-on', on);
    state.cat = on ? p.dataset.cat : ''; apply();
  }));
})();
