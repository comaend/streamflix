// StreamFlix — Admin-JS (API-Hilfsfunktionen für Admin-Aktionen)
(function(){
  const base = '/api/admin.php?action=';
  async function apiPost(action, data){
    const res = await fetch(base + action, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(data || {})
    });
    return res.json();
  }
  async function apiGet(action, params){
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    const res = await fetch(base + action + qs);
    return res.json();
  }
  window.StreamFlixAdmin = { apiPost, apiGet };

  document.addEventListener('click', async function(e){
    const btn = e.target.closest('[data-sf-action]');
    if (!btn) return;
    e.preventDefault();
    const action = btn.getAttribute('data-sf-action');
    const id = btn.getAttribute('data-sf-id');
    const confirmMsg = btn.getAttribute('data-sf-confirm');
    if (confirmMsg && !confirm(confirmMsg)) return;
    const btnText = btn.textContent;
    btn.textContent = '...';
    btn.disabled = true;
    try {
      let result;
      switch (action) {
        case 'user_delete':   result = await window.StreamFlixAdmin.apiPost('user_delete', {id: parseInt(id)}); break;
        case 'user_lock':     result = await window.StreamFlixAdmin.apiPost('user_lock', {id: parseInt(id)}); break;
        case 'user_unlock':   result = await window.StreamFlixAdmin.apiPost('user_unlock', {id: parseInt(id)}); break;
        case 'user_password_reset': result = await window.StreamFlixAdmin.apiPost('user_password_reset', {id: parseInt(id), new_password: btn.getAttribute('data-sf-pw') || 'StreamFlix2024!'}); break;
        case 'user_role':     result = await window.StreamFlixAdmin.apiPost('user_update', {id: parseInt(id), role: btn.getAttribute('data-sf-role')}); break;
        case 'profile_delete':result = await window.StreamFlixAdmin.apiPost('profile_delete', {id: parseInt(id)}); break;
        case 'folder_delete': result = await window.StreamFlixAdmin.apiPost('folder_delete', {id: parseInt(id)}); break;
        case 'folder_scan':   result = await window.StreamFlixAdmin.apiPost('folder_scan', {id: parseInt(id)}); break;
        case 'stream_stop':   result = await window.StreamFlixAdmin.apiPost('stream_stop', {id: parseInt(id)}); break;
        case 'backup_create': result = await window.StreamFlixAdmin.apiPost('backup_create', {}); break;
        case 'backup_restore':result = await window.StreamFlixAdmin.apiPost('backup_restore', {name: btn.getAttribute('data-sf-name') || '', id: parseInt(id) || 0}); break;
        default: result = {error: 'Unbekannte Aktion'};
      }
      if (result.error) { alert('Fehler: ' + result.error); }
      else { location.reload(); }
    } catch (err) {
      alert('Netzwerkfehler.');
    } finally {
      btn.textContent = btnText;
      btn.disabled = false;
    }
  });
})();
