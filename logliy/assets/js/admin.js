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
        if (preview) {
          preview.hidden = true;
          preview.innerHTML = '';
        }
        clearBtn.hidden = true;
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
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
