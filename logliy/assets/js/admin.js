/**
 * Admin settings helpers (logo / background media pickers).
 */
(function () {
  'use strict';

  function bindMediaPicker(opts) {
    var uploadBtn = document.querySelector(opts.upload);
    var clearBtn = document.querySelector(opts.clear);
    var input = document.getElementById(opts.inputId);
    var preview = document.querySelector(opts.preview);
    if (!uploadBtn || !input || typeof wp === 'undefined' || !wp.media) return;

    var frame = null;
    uploadBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (frame) {
        frame.open();
        return;
      }
      frame = wp.media({
        title: opts.title,
        button: { text: opts.button },
        multiple: false,
        library: { type: 'image' },
      });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        input.value = attachment.id;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        var url =
          (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
          attachment.url;
        if (preview) {
          preview.hidden = false;
          preview.innerHTML =
            '<img src="' +
            url +
            '" alt="" style="max-height:' +
            (opts.maxHeight || 64) +
            'px;max-width:200px;display:block;margin-bottom:8px" />';
        }
        if (clearBtn) clearBtn.hidden = false;
      });
      frame.open();
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        input.value = '0';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        if (preview) {
          preview.hidden = true;
          preview.innerHTML = '';
        }
        clearBtn.hidden = true;
      });
    }
  }

  function bindAutoSave() {
    var form = document.querySelector('.lg-form');
    if (!form || !window.logliyAdmin || !window.fetch) return;

    var config = window.logliyAdmin;
    var statuses = document.querySelectorAll('[data-logliy-save-status]');
    var timer;
    var busy = false;
    var manualPending = false;
    var secret = form.querySelector('[name="logliy_settings[oidc_client_secret]"]');
    var serialize = function () { return new URLSearchParams(new FormData(form)).toString(); };
    var saved = serialize();
    var observed = saved;
    var dirty = function () { return serialize() !== saved; };

    function status(message, state) {
      statuses.forEach(function (node) {
        node.textContent = message;
        node.dataset.state = state || '';
      });
    }

    function normalize(settings) {
      Array.from(form.elements).forEach(function (input) {
        var match = input.name.match(/^logliy_settings\[([^\]]+)\](?:\[([^\]]*)\])?$/);
        if (!match || !Object.prototype.hasOwnProperty.call(settings, match[1])) return;
        var value = settings[match[1]];
        if (Array.isArray(value)) {
          if (input.type === 'checkbox') input.checked = value.indexOf(input.value) !== -1;
        } else if (value && typeof value === 'object') {
          if (match[2] !== undefined) input.value = value[match[2]] || '';
        } else if (input.type === 'checkbox') {
          input.checked = !!value;
        } else {
          input.value = value == null ? '' : String(value);
        }
      });
    }

    async function save(manual) {
      clearTimeout(timer);
      if (busy) { if (manual) manualPending = true; return; }
      if (!manual && !dirty()) return;
      if (!form.checkValidity()) {
        status(config.i18n.invalid, 'error');
        if (manual) form.reportValidity();
        return;
      }
      var sent = serialize();
      var submittedSecret = secret ? secret.value : '';
      var body = new URLSearchParams(sent);
      body.set('action', 'logliy_save_settings');
      busy = true;
      form.setAttribute('aria-busy', 'true');
      status(config.i18n.saving, 'saving');
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 15000);
      try {
        var response = await window.fetch(config.ajaxUrl, {
          method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal,
        });
        var result = await response.json();
        if (!response.ok || !result.success) {
          var failure = new Error(result.data && result.data.message ? result.data.message : config.i18n.failed);
          failure.isSaveError = true;
          throw failure;
        }
        // An unrelated later edit must not cause the secret to be sent twice.
        if (secret && secret.value === submittedSecret) {
          secret.value = '';
          var previous = new URLSearchParams(sent);
          previous.set(secret.name, '');
          sent = previous.toString();
        }
        if (serialize() === sent) {
          normalize(result.data.settings || {});
          saved = serialize();
        } else {
          saved = sent;
        }
        observed = serialize();
        status(dirty() ? config.i18n.unsaved : config.i18n.saved, dirty() ? 'unsaved' : 'saved');
      } catch (error) {
        status(error.isSaveError ? error.message : config.i18n.failed, 'error');
      } finally {
        clearTimeout(timeout);
        busy = false;
        form.removeAttribute('aria-busy');
        if (manualPending) {
          manualPending = false;
          save(true);
        } else if (dirty() && serialize() !== sent) {
          timer = setTimeout(function () { save(false); }, 250);
        }
      }
    }

    function changed(event) {
      if (!event.target.name || event.target.name.indexOf('logliy_settings[') !== 0) return;
      var current = serialize();
      if (current === observed) return;
      observed = current;
      status(dirty() ? config.i18n.unsaved : config.i18n.saved, dirty() ? 'unsaved' : 'saved');
      clearTimeout(timer);
      if (dirty()) timer = setTimeout(function () { save(false); }, event.type === 'input' ? 800 : 250);
    }

    status(config.i18n.automatic, '');
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('submit', function (event) { event.preventDefault(); save(true); });
    window.addEventListener('beforeunload', function (event) {
      if (busy || dirty()) { event.preventDefault(); event.returnValue = ''; }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindAutoSave();
    bindMediaPicker({
      upload: '[data-logliy-logo-upload]',
      clear: '[data-logliy-logo-clear]',
      inputId: 'login_logo_id',
      preview: '[data-logliy-logo-preview]',
      title: 'Select logo',
      button: 'Use logo',
      maxHeight: 64,
    });
    bindMediaPicker({
      upload: '[data-logliy-bg-upload]',
      clear: '[data-logliy-bg-clear]',
      inputId: 'login_bg_image_id',
      preview: '[data-logliy-bg-preview]',
      title: 'Select background',
      button: 'Use image',
      maxHeight: 80,
    });
  });
})();
