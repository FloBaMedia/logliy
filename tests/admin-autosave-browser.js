// Evaluate this function in a disposable rendered Logliy Email settings page.
// Tests request ordering and failure recovery with intercepted fetch; no real save requests.
async () => {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const form = document.querySelector('.lg-form');
  const field = form.querySelector('[name="logliy_settings[otp_rate_limit_account]"]');
  const status = () => document.querySelector('[data-logliy-save-status]').textContent;
  const originalFetch = window.fetch;
  let checks = 0;
  const assert = (value, message) => { if (!value) throw new Error(message); checks++; };
  const calls = [];
  let active = 0;
  let maxActive = 0;
  let delay = 20;
  let fail = false;
  let reject = false;
  let persisted;
  let persistedAllowlist;
  window.fetch = async (url, options) => {
    const body = new URLSearchParams(options.body);
    calls.push(body);
    active++;
    maxActive = Math.max(maxActive, active);
    await wait(delay);
    active--;
    if (fail) { fail = false; throw new Error('Simulated offline'); }
    if (reject) { reject = false; return {ok: false, json: async () => ({success: false, data: {message: 'Validation refused'}})}; }
    persisted = Number(body.get('logliy_settings[otp_rate_limit_account]'));
    persistedAllowlist = body.get('logliy_settings[email_allowlist_enabled]') === '1';
    return {ok: true, json: async () => ({success: true, data: {settings: {otp_rate_limit_account: persisted, email_allowlist_enabled: persistedAllowlist}}})};
  };
  const edit = value => { field.value = String(value); field.dispatchEvent(new Event('input', {bubbles: true})); };
  const submit = () => document.querySelector('.lg-header .lg-save-btn').click();
  try {
    edit(7);
    await wait(950);
    assert(persisted === 7, 'auto-save is active immediately without opting in');
    const policy = form.querySelector('[name="logliy_settings[email_allowlist_enabled]"]');
    policy.checked = true; policy.dispatchEvent(new Event('change', {bubbles: true})); await wait(350);
    assert(persistedAllowlist === true, 'turning a setting on auto-saves the checked value');
    policy.checked = false; policy.dispatchEvent(new Event('change', {bubbles: true})); await wait(350);
    assert(persistedAllowlist === false, 'turning a setting off auto-saves the unchecked value');
    const count = calls.length;
    edit(8); edit(9);
    await wait(950);
    assert(calls.length === count + 1 && persisted === 9, 'rapid text edits debounce into one save');
    delay = 400;
    edit(10); submit();
    await wait(30);
    edit(11);
    await wait(1200);
    assert(persisted === 11 && maxActive === 1, 'new edits during save are queued without overlapping requests');
    assert(status() === window.logliyAdmin.i18n.saved, 'only newest persisted values show saved');
    delay = 20;
    fail = true;
    edit(12); submit();
    await wait(80);
    const afterFailure = calls.length;
    assert(status() === window.logliyAdmin.i18n.failed, 'network failure shows unsaved error');
    await wait(1000);
    assert(calls.length === afterFailure, 'failed request does not enter an automatic retry loop');
    submit(); await wait(80);
    assert(persisted === 12 && status() === window.logliyAdmin.i18n.saved, 'manual retry recovers pending changes');
    const beforeInvalid = calls.length;
    edit(0); await wait(950);
    assert(calls.length === beforeInvalid && status() === window.logliyAdmin.i18n.invalid, 'invalid fields block saving without a request');
    edit(13); await wait(950);
    assert(persisted === 13, 'corrected invalid input can save');
    edit(14); submit(); await wait(80);
    assert(persisted === 14, 'manual saving remains available with automatic saving');
    reject = true; edit(15); submit(); await wait(80);
    assert(persisted === 14 && status() === 'Validation refused', 'server validation errors leave edits unsaved and show the reason');
    submit(); await wait(80);
    assert(persisted === 15, 'manual retry after server rejection succeeds');
    assert(calls.every(body => body.get('action') === 'logliy_save_settings' && body.get('_wpnonce')), 'every save includes action and Settings API nonce');
    return {checks, requests: calls.length, maxConcurrentRequests: maxActive, persisted};
  } finally {
    window.fetch = originalFetch;
  }
};
