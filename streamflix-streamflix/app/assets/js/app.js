// StreamFlix — Hauptanwendung (Frontend-Interaktionen)
(function(){
  const API = '/api';

  async function api(path, {method='GET', body}={}){
    const opts = {method, headers:{'Content-Type':'application/json'}};
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(API + path, opts);
    return res.json();
  }

  // CSRF-Token holen
  async function getCsrf(){
    const r = await api('/auth.php?action=csrf');
    return r.csrf_token || '';
  }

  // Watchlist toggle
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('[data-wl]');
    if (!btn) return;
    e.preventDefault();
    const type = btn.getAttribute('data-wl-type');
    const id = parseInt(btn.getAttribute('data-wl-id'));
    const token = await getCsrf();
    if (btn.classList.contains('in-wl')) {
      await api('/watchlist.php?action=remove', {method:'POST', body:{type,id}});
      btn.classList.remove('in-wl');
      btn.textContent = '+ Zur Liste';
    } else {
      await api('/watchlist.php?action=add', {method:'POST', body:{type,id}});
      btn.classList.add('in-wl');
      btn.textContent = '✓ In Liste';
    }
  });

  // Bewertung
  document.addEventListener('click', async function(e){
    const btn = e.target.closest('[data-rate]');
    if (!btn) return;
    e.preventDefault();
    const type = btn.getAttribute('data-rate-type');
    const id = parseInt(btn.getAttribute('data-rate-id'));
    const rating = parseInt(btn.getAttribute('data-rate'));
    const token = await getCsrf();
    await api('/ratings.php?action=set', {method:'POST', body:{type,id,rating}});
    document.querySelectorAll('[data-rate-type="'+type+'"][data-rate-id="'+id+'"]').forEach(b=>{
      b.classList.toggle('active', parseInt(b.getAttribute('data-rate')) === rating);
    });
  });

  // Resume-Hinweis auf Karten
  document.querySelectorAll('[data-resume]').forEach(el=>{
    el.style.display = 'inline-block';
  });
})();
