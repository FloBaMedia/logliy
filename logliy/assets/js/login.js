/**
 * Logliy login UI controller.
 */
(function () {
  'use strict';

  var cfg = window.logliyLogin || null;
  if (!cfg) return;

  var conditionalStarted = false;
  var emailCooldownTimer = null;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function getTurnstileToken() {
    var input = document.querySelector('input[name="cf-turnstile-response"]');
    if (input && input.value) return input.value;
    var inputs = document.querySelectorAll('[name="cf-turnstile-response"]');
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].value) return inputs[i].value;
    }
    return '';
  }

  function resetTurnstile() {
    if (!window.turnstile || typeof window.turnstile.reset !== 'function') return;
    try {
      window.turnstile.reset();
    } catch (e) {
      /* ignore */
    }
    $all('.cf-turnstile').forEach(function (el) {
      try {
        window.turnstile.reset(el);
      } catch (err) {
        /* ignore */
      }
    });
  }

  /**
   * Only block when the server says Captcha is required (configured Turnstile).
   * Orphan DOM must not invent a Captcha requirement.
   */
  function ensureCaptcha() {
    if (!cfg.turnstileRequired) {
      return true;
    }
    return !!getTurnstileToken();
  }

  function friendlyWebAuthnError(err) {
    if (!err) return cfg.i18n.passkeyFail;
    var msg = String(err.message || err);
    var name = err.name || '';
    if (name === 'AbortError') {
      return cfg.i18n.passkeyFail;
    }
    if (name === 'InvalidStateError' || /already pending/i.test(msg)) {
      return cfg.i18n.passkeyBusy || cfg.i18n.passkeyFail;
    }
    if (name === 'NotAllowedError') {
      return cfg.i18n.passkeyFail;
    }
    return msg || cfg.i18n.passkeyFail;
  }

  async function api(path, body) {
    body = body || {};
    var token = getTurnstileToken();
    if (token) body.cf_turnstile_response = token;

    var res = await fetch(cfg.restUrl + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      body: JSON.stringify(body),
    });
    var data = null;
    try {
      data = await res.json();
    } catch (e) {
      data = null;
    }
    if (!res.ok) {
      var msg =
        (data && data.message) ||
        (data && data.code) ||
        cfg.i18n.errorGeneric;
      var err = new Error(msg);
      var retry =
        (data && data.data && data.data.retry_after) ||
        (data && data.retry_after) ||
        0;
      if (retry) err.retryAfter = parseInt(retry, 10);
      throw err;
    }
    return data;
  }

  function showMsg(panel, text, ok) {
    var el = $('[data-logliy-msg]', panel);
    if (!el) return;
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.toggle('is-ok', !!ok);
    el.classList.toggle('is-error', !ok && !!text);
  }

  function setBusy(btn, busy) {
    if (!btn) return;
    btn.disabled = !!busy;
    if (busy) {
      btn.dataset.label = btn.textContent;
      btn.textContent = cfg.i18n.working;
    } else if (btn.dataset.label) {
      btn.textContent = btn.dataset.label;
    }
  }

  function emailButtons(panel) {
    return $all('[data-logliy-otp-request], [data-logliy-otp-resend], [data-logliy-magic-request]', panel);
  }

  function startEmailCooldown(panel, seconds) {
    seconds = parseInt(seconds, 10);
    if (!seconds || seconds < 1) {
      seconds = cfg.emailCooldown || 60;
    }
    if (emailCooldownTimer) {
      clearInterval(emailCooldownTimer);
      emailCooldownTimer = null;
    }
    var left = seconds;
    var buttons = emailButtons(panel);

    function tick() {
      buttons.forEach(function (btn) {
        if (!btn.dataset.label) btn.dataset.label = btn.textContent;
        btn.disabled = true;
        var tpl = cfg.i18n.waitSeconds || 'Wait %d s';
        btn.textContent = tpl.replace('%d', String(left));
      });
      if (left <= 0) {
        clearInterval(emailCooldownTimer);
        emailCooldownTimer = null;
        buttons.forEach(function (btn) {
          btn.disabled = false;
          if (btn.dataset.label) btn.textContent = btn.dataset.label;
        });
        return;
      }
      left -= 1;
    }

    tick();
    emailCooldownTimer = setInterval(tick, 1000);
  }

  function activateTab(panel, name) {
    $all('.logliy-tab', panel).forEach(function (tab) {
      var on = tab.getAttribute('data-logliy-tab') === name;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    $all('[data-logliy-pane]', panel).forEach(function (pane) {
      pane.classList.toggle('is-active', pane.getAttribute('data-logliy-pane') === name);
    });
    if (name === 'password') {
      document.body.classList.remove('logliy-passwordless');
    } else if (cfg.hidePassword) {
      document.body.classList.add('logliy-passwordless');
    }
  }

  function remember(panel) {
    var boxes = $all('[data-logliy-remember]', panel);
    if (boxes.length) {
      return boxes.some(function (b) {
        return b.checked;
      });
    }
    return !!cfg.autoRemember;
  }

  function initPanel(panel) {
    panel.hidden = false;

    $all('.logliy-tab', panel).forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateTab(panel, tab.getAttribute('data-logliy-tab'));
        showMsg(panel, '', true);
      });
    });

    var first = $('.logliy-tab.is-active', panel) || $('.logliy-tab', panel);
    if (first) activateTab(panel, first.getAttribute('data-logliy-tab'));

    var passkeyBtn = $('[data-logliy-passkey-auth]', panel);
    if (passkeyBtn) {
      passkeyBtn.addEventListener('click', async function () {
        showMsg(panel, '', true);
        if (!ensureCaptcha()) {
          showMsg(panel, cfg.i18n.captchaRequired, false);
          return;
        }
        setBusy(passkeyBtn, true);
        try {
          if (!window.LogliyPasskey || !LogliyPasskey.supported) {
            throw new Error(cfg.i18n.passkeyFail);
          }
          // Abort Conditional UI and wait for browser to release the pending get().
          if (LogliyPasskey.abortConditionalAndWait) {
            await LogliyPasskey.abortConditionalAndWait();
          } else if (LogliyPasskey.abortConditional) {
            LogliyPasskey.abortConditional();
            await new Promise(function (r) {
              setTimeout(r, 200);
            });
          }

          var login = ($('#logliy-passkey-login', panel) || {}).value || '';
          var opts = await api('/passkey/auth/options', { login: login });
          var cred = await LogliyPasskey.getCredential(opts.options);
          if (!cred) throw new Error(cfg.i18n.passkeyFail);
          var result = await api('/passkey/auth/verify', {
            challenge_id: opts.challenge_id,
            credential: cred,
            remember: remember(panel),
            redirect_to: cfg.redirectTo || '',
          });
          window.location.href = result.redirect || '/';
        } catch (err) {
          showMsg(panel, friendlyWebAuthnError(err), false);
          resetTurnstile();
        } finally {
          setBusy(passkeyBtn, false);
        }
      });
    }

    // Conditional UI only after focusing the Passkey username field (avoids pending vs button).
    var passkeyInput = $('#logliy-passkey-login', panel);
    if (passkeyInput && cfg.enablePasskey) {
      passkeyInput.addEventListener('focus', function () {
        startConditionalUi();
      });
    }

    async function requestOtp() {
      if (!ensureCaptcha()) {
        showMsg(panel, cfg.i18n.captchaRequired, false);
        return;
      }
      var loginEl = $('#logliy-otp-login', panel);
      var login = loginEl ? loginEl.value.trim() : '';
      var btn = $('[data-logliy-otp-request]', panel);
      setBusy(btn, true);
      showMsg(panel, '', true);
      try {
        var data = await api('/otp/request', { login: login });
        showMsg(panel, (data && data.message) || cfg.i18n.otpSent, true);
        startEmailCooldown(panel, (data && data.cooldown) || cfg.emailCooldown);
        resetTurnstile();
        var req = $('[data-logliy-otp-step="request"]', panel);
        var ver = $('[data-logliy-otp-step="verify"]', panel);
        if (req) req.hidden = true;
        if (ver) ver.hidden = false;
      } catch (err) {
        showMsg(panel, (err && err.message) || cfg.i18n.errorGeneric, false);
        if (err && err.retryAfter) startEmailCooldown(panel, err.retryAfter);
        resetTurnstile();
      } finally {
        setBusy(btn, false);
      }
    }

    var otpReq = $('[data-logliy-otp-request]', panel);
    if (otpReq) otpReq.addEventListener('click', requestOtp);
    var otpResend = $('[data-logliy-otp-resend]', panel);
    if (otpResend) otpResend.addEventListener('click', requestOtp);

    var otpVerify = $('[data-logliy-otp-verify]', panel);
    if (otpVerify) {
      otpVerify.addEventListener('click', async function () {
        if (!ensureCaptcha()) {
          showMsg(panel, cfg.i18n.captchaRequired, false);
          return;
        }
        var login = (($('#logliy-otp-login', panel) || {}).value || '').trim();
        var code = (($('#logliy-otp-code', panel) || {}).value || '').trim();
        setBusy(otpVerify, true);
        showMsg(panel, '', true);
        try {
          var result = await api('/otp/verify', {
            login: login,
            code: code,
            remember: remember(panel),
            redirect_to: cfg.redirectTo || '',
          });
          window.location.href = result.redirect || '/';
        } catch (err) {
          showMsg(panel, (err && err.message) || cfg.i18n.errorGeneric, false);
          resetTurnstile();
        } finally {
          setBusy(otpVerify, false);
        }
      });
    }

    var magicBtn = $('[data-logliy-magic-request]', panel);
    if (magicBtn) {
      magicBtn.addEventListener('click', async function () {
        if (!ensureCaptcha()) {
          showMsg(panel, cfg.i18n.captchaRequired, false);
          return;
        }
        var loginEl = $('#logliy-magic-login', panel);
        var login = loginEl ? loginEl.value.trim() : '';
        setBusy(magicBtn, true);
        showMsg(panel, '', true);
        try {
          var data = await api('/magic/request', {
            login: login,
            redirect_to: cfg.redirectTo || '',
          });
          showMsg(panel, (data && data.message) || cfg.i18n.magicSent, true);
          startEmailCooldown(panel, (data && data.cooldown) || cfg.emailCooldown);
          resetTurnstile();
        } catch (err) {
          showMsg(panel, (err && err.message) || cfg.i18n.errorGeneric, false);
          if (err && err.retryAfter) startEmailCooldown(panel, err.retryAfter);
          resetTurnstile();
        } finally {
          setBusy(magicBtn, false);
        }
      });
    }
  }

  /**
   * Conditional UI: start once (on focus), not on page load alongside the Passkey button.
   */
  function startConditionalUi() {
    if (conditionalStarted) return;
    if (!cfg.enablePasskey || !window.LogliyPasskey || !LogliyPasskey.supported) return;
    conditionalStarted = true;

    (async function () {
      try {
        if (cfg.turnstileRequired) {
          if (!getTurnstileToken()) {
            // Don't leave a pending get without captcha; allow retry on next focus.
            conditionalStarted = false;
            return;
          }
        }
        var opts = await api('/passkey/auth/options', { login: '' });
        var cred = await LogliyPasskey.conditionalGet(opts.options);
        if (!cred) return;
        var result = await api('/passkey/auth/verify', {
          challenge_id: opts.challenge_id,
          credential: cred,
          remember: !!cfg.autoRemember,
          redirect_to: cfg.redirectTo || '',
        });
        window.location.href = result.redirect || '/';
      } catch (e) {
        /* ignore abort / unavailable */
        conditionalStarted = false;
      }
    })();
  }

  function boot() {
    $all('.logliy-panel').forEach(initPanel);
    // Do NOT auto-start Conditional UI on load — that caused "already pending" with the button.
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
