/**
 * Profile Passkey management.
 */
(function () {
  'use strict';

  var cfg = window.logliyProfile || null;
  if (!cfg) return;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  async function api(path, opts) {
    opts = opts || {};
    var res = await fetch(cfg.restUrl + path, {
      method: opts.method || 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    var data = null;
    try {
      data = await res.json();
    } catch (e) {
      data = null;
    }
    if (!res.ok) {
      throw new Error((data && data.message) || cfg.i18n.fail);
    }
    return data;
  }

  function msg(text, ok) {
    var el = $('[data-logliy-profile-msg]');
    if (!el) return;
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.toggle('is-ok', !!ok);
    el.classList.toggle('is-error', !ok && !!text);
  }

  function bindList() {
    var list = $('[data-logliy-passkey-list]');
    if (!list) return;
    list.addEventListener('click', async function (ev) {
      var t = ev.target;
      if (!(t instanceof HTMLElement)) return;
      var li = t.closest('li[data-id]');
      if (!li) return;
      var id = li.getAttribute('data-id');
      if (t.matches('[data-logliy-delete]')) {
        if (!window.confirm(cfg.i18n.confirmDelete)) return;
        try {
          await api('/passkey/' + id, { method: 'DELETE' });
          li.remove();
          msg(cfg.i18n.removed || cfg.i18n.success, true);
          if (!$('[data-logliy-passkey-list] li')) {
            var list = $('[data-logliy-passkey-list]');
            if (list) {
              list.innerHTML = '<p class="description">' + (cfg.i18n.empty || '') + '</p>';
            }
          }
        } catch (err) {
          msg(err.message, false);
        }
      }
      if (t.matches('[data-logliy-rename]')) {
        var current = (li.querySelector('.logliy-cred-name') || {}).textContent || '';
        var name = window.prompt(cfg.i18n.rename, current);
        if (!name) return;
        try {
          await api('/passkey/' + id, { method: 'PATCH', body: { name: name } });
          var nameEl = li.querySelector('.logliy-cred-name');
          if (nameEl) nameEl.textContent = name;
          msg(cfg.i18n.renamed || cfg.i18n.success, true);
        } catch (err) {
          msg(err.message, false);
        }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindList();
    var btn = $('[data-logliy-register-passkey]');
    if (!btn) return;
    btn.addEventListener('click', async function () {
      msg('', true);
      btn.disabled = true;
      var label = btn.textContent;
      btn.textContent = cfg.i18n.working;
      try {
        if (!window.LogliyPasskey || !LogliyPasskey.supported) {
          throw new Error(cfg.i18n.fail);
        }
        var opts = await api('/passkey/register/options', { method: 'POST', body: {} });
        var name = window.prompt(cfg.i18n.register, 'Passkey') || 'Passkey';
        var cred = await LogliyPasskey.createCredential(opts.options);
        await api('/passkey/register/verify', {
          method: 'POST',
          body: { challenge_id: opts.challenge_id, credential: cred, name: name },
        });
        msg(cfg.i18n.success, true);
        window.location.reload();
      } catch (err) {
        msg((err && err.message) || cfg.i18n.fail, false);
      } finally {
        btn.disabled = false;
        btn.textContent = label;
      }
    });
  });
})();
