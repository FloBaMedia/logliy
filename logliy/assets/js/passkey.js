/**
 * WebAuthn helpers (vanilla JS).
 */
(function (global) {
  'use strict';

  var conditionalAbort = null;
  var conditionalPromise = null;
  var modalInFlight = false;

  function b64uToBuf(b64u) {
    var s = b64u.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }

  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf);
    var str = '';
    for (var i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  function preparePublicKeyOptions(options) {
    var o = JSON.parse(JSON.stringify(options));
    if (o.challenge) o.challenge = b64uToBuf(o.challenge);
    if (o.user && o.user.id) o.user.id = b64uToBuf(o.user.id);
    if (o.excludeCredentials) {
      o.excludeCredentials = o.excludeCredentials.map(function (c) {
        c.id = b64uToBuf(c.id);
        return c;
      });
    }
    if (o.allowCredentials) {
      o.allowCredentials = o.allowCredentials.map(function (c) {
        c.id = b64uToBuf(c.id);
        return c;
      });
    }
    return o;
  }

  function credentialToJSON(cred) {
    if (!cred) return null;
    var response = cred.response;
    var out = {
      id: cred.id,
      rawId: bufToB64u(cred.rawId),
      type: cred.type,
      clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
      response: {
        clientDataJSON: bufToB64u(response.clientDataJSON),
      },
    };
    if (response.attestationObject) {
      out.response.attestationObject = bufToB64u(response.attestationObject);
      if (response.getTransports) out.response.transports = response.getTransports();
    }
    if (response.authenticatorData) {
      out.response.authenticatorData = bufToB64u(response.authenticatorData);
      out.response.signature = bufToB64u(response.signature);
      if (response.userHandle) out.response.userHandle = bufToB64u(response.userHandle);
    }
    return out;
  }

  function abortConditional() {
    if (conditionalAbort) {
      try {
        conditionalAbort.abort('logliy-switch-to-modal');
      } catch (e) {
        /* ignore */
      }
      conditionalAbort = null;
    }
  }

  /**
   * Abort Conditional UI and wait until the browser releases the pending get().
   * Chrome often throws "A request is already pending" if modal get starts too soon.
   */
  async function abortConditionalAndWait() {
    var pending = conditionalPromise;
    abortConditional();
    if (pending) {
      try {
        await pending;
      } catch (e) {
        /* ignore abort */
      }
    }
    conditionalPromise = null;
    // Extra settle time — Chromium needs this after aborting conditional mediation.
    await new Promise(function (resolve) {
      setTimeout(resolve, 200);
    });
  }

  async function createCredential(publicKey) {
    await abortConditionalAndWait();
    var cred = await navigator.credentials.create({
      publicKey: preparePublicKeyOptions(publicKey),
    });
    return credentialToJSON(cred);
  }

  /**
   * Modal (button) authentication — never overlaps Conditional UI.
   */
  async function getCredential(publicKey) {
    if (modalInFlight) {
      var err = new Error('A request is already pending.');
      err.name = 'InvalidStateError';
      throw err;
    }
    await abortConditionalAndWait();
    modalInFlight = true;
    try {
      var opts = { publicKey: preparePublicKeyOptions(publicKey) };
      var cred = await navigator.credentials.get(opts);
      return credentialToJSON(cred);
    } finally {
      modalInFlight = false;
    }
  }

  /**
   * Conditional UI (autofill). Only one may be pending.
   */
  async function conditionalGet(publicKey) {
    if (
      !(
        window.PublicKeyCredential &&
        PublicKeyCredential.isConditionalMediationAvailable &&
        (await PublicKeyCredential.isConditionalMediationAvailable())
      )
    ) {
      return null;
    }
    if (modalInFlight) {
      return null;
    }
    await abortConditionalAndWait();
    conditionalAbort = new AbortController();
    var signal = conditionalAbort.signal;

    conditionalPromise = (async function () {
      try {
        var opts = {
          publicKey: preparePublicKeyOptions(publicKey),
          mediation: 'conditional',
          signal: signal,
        };
        var cred = await navigator.credentials.get(opts);
        return credentialToJSON(cred);
      } catch (err) {
        var name = err && err.name;
        if (name === 'AbortError' || name === 'NotAllowedError') {
          return null;
        }
        // "already pending" during conditional — treat as unavailable.
        if (name === 'InvalidStateError' || /already pending/i.test(String(err && err.message))) {
          return null;
        }
        throw err;
      } finally {
        if (conditionalAbort && conditionalAbort.signal === signal) {
          conditionalAbort = null;
        }
      }
    })();

    try {
      return await conditionalPromise;
    } finally {
      if (conditionalPromise) {
        /* cleared when settled */
      }
      conditionalPromise = null;
    }
  }

  global.LogliyPasskey = {
    createCredential: createCredential,
    getCredential: getCredential,
    conditionalGet: conditionalGet,
    abortConditional: abortConditional,
    abortConditionalAndWait: abortConditionalAndWait,
    supported: !!(window.PublicKeyCredential && navigator.credentials),
  };
})(window);
