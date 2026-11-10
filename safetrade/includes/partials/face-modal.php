<?php
/**
 * Face verification camera modal. Included by auth/login.php and
 * auth/register.php. window.openFaceModal(mode, callbacks) — mode 'login'|'register'.
 */
?>
<style>
.face-trigger-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:13px 22px;border-radius:999px;font-weight:600;font-size:14.5px;background:var(--cream);color:var(--brand);border:1.5px solid var(--brand);cursor:pointer;transition:all .2s ease;}
.face-trigger-btn:hover{background:var(--brand-light);transform:translateY(-1px);}
.auth-divider{display:flex;align-items:center;gap:12px;margin:18px 0;font-size:11.5px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;}
.auth-divider::before,.auth-divider::after{content:"";flex:1;height:1px;background:var(--line);}
.face-modal-backdrop{position:fixed;inset:0;background:rgba(13,58,44,.55);display:none;align-items:center;justify-content:center;z-index:500;padding:20px;}
.face-modal-backdrop.show{display:flex;}
.face-modal{background:var(--cream);border-radius:22px;padding:26px;width:100%;max-width:420px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.1);}
.face-modal h3{font-family:'Fraunces',serif;font-size:18px;color:var(--ink);margin:0;}
.face-modal .face-sub{font-size:12px;color:var(--ink-soft);margin-top:4px;}
.face-video-wrap{position:relative;margin-top:18px;border-radius:16px;overflow:hidden;background:#0D3A2C;aspect-ratio:4/3;border:2px solid rgba(255,255,255,.1);}
.face-video-wrap video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);}
.face-status-pill{margin-top:14px;font-size:13px;font-weight:600;color:var(--brand);min-height:18px;display:flex;align-items:center;justify-content:center;gap:6px;}
.face-status-pill.loading::after{content:'';display:inline-block;width:12px;height:12px;border:2px solid var(--brand);border-right-color:transparent;border-radius:50%;animation:spin .6s linear infinite;}
.face-status-pill.success{color:#2ecc71;}
.face-status-pill.error{color:var(--danger);}
@keyframes spin{to{transform:rotate(360deg)}}
.face-progress{margin-top:10px;height:8px;border-radius:99px;background:var(--line);overflow:hidden;}
.face-progress-fill{height:100%;width:0%;background:var(--gold);transition:width .25s ease;}
.face-error{margin-top:12px;padding:10px 12px;border-radius:8px;font-size:12px;color:#c0392b;background:rgba(192,57,43,.1);display:none;border-left:3px solid #c0392b;text-align:left;}
.face-error.show{display:block;}
.face-modal-actions{margin-top:18px;display:flex;gap:10px;justify-content:center;flex-direction:column;}
.face-modal-actions>button{padding:11px 18px;font-size:13px;font-weight:600;border-radius:8px;border:1.5px solid var(--line);background:transparent;color:var(--ink-soft);cursor:pointer;transition:all .2s ease;}
.face-modal-actions>button:hover{background:var(--line);}
.face-retry-info{margin-top:12px;font-size:11px;color:var(--ink-soft);line-height:1.5;}
@media (max-width:480px){.face-modal{max-width:100%;padding:20px;}.face-modal h3{font-size:16px;}}
</style>

<div class="face-modal-backdrop" id="face-modal-backdrop">
  <div class="face-modal">
    <h3>SafeTrade — Face Verification</h3>
    <p class="face-sub" id="face-modal-sub">Center your face in the frame and follow the instructions.</p>
    <div class="face-video-wrap" id="face-video-wrap"><video id="face-video" muted playsinline autoplay></video></div>
    <div class="face-status-pill loading" id="face-status">Loading…</div>
    <div class="face-progress"><div class="face-progress-fill" id="face-progress-fill"></div></div>
    <div class="face-error" id="face-error"></div>
    <div class="face-retry-info" id="face-retry-info"></div>
    <div class="face-modal-actions">
      <button type="button" id="face-modal-retry" style="display:none;">Try Again</button>
      <button type="button" id="face-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

<script>window.SAFETRADE_BASE = '<?= e(BASE_URL) ?>';</script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="<?= e($assetBase ?? '') ?>/assets/js/face-auth.js"></script>
<script>
(function () {
  var backdrop   = document.getElementById('face-modal-backdrop');
  var statusEl   = document.getElementById('face-status');
  var subEl      = document.getElementById('face-modal-sub');
  var progressEl = document.getElementById('face-progress-fill');
  var errorEl    = document.getElementById('face-error');
  var retryBtn   = document.getElementById('face-modal-retry');
  var cancelBtn  = document.getElementById('face-modal-cancel');
  var videoEl    = document.getElementById('face-video');
  var retryInfo  = document.getElementById('face-retry-info');
  var API        = '<?= e(BASE_URL) ?>/face-verify-api.php';
  var MAX_RETRIES = 3;

  var session = null, busy = false, isOpen = false, retryCount = 0;
  var mode = null, callbacks = null;

  function reset() {
    errorEl.textContent = ''; errorEl.classList.remove('show');
    progressEl.style.width = '0%';
    statusEl.textContent = 'Loading…'; statusEl.className = 'face-status-pill loading';
    retryBtn.style.display = 'none'; retryInfo.textContent = '';
  }

  function close() {
    isOpen = false;
    if (session) session.stopCamera();
    backdrop.classList.remove('show');
    retryCount = 0;
  }

  function showError(message, allowRetry) {
    errorEl.textContent = message; errorEl.classList.add('show');
    statusEl.className = 'face-status-pill error';
    statusEl.textContent = 'Verification failed';
    if (allowRetry && retryCount < MAX_RETRIES) {
      retryBtn.style.display = 'block';
      retryInfo.textContent = 'Attempt ' + (retryCount + 1) + ' of ' + MAX_RETRIES;
    } else if (allowRetry) {
      retryInfo.textContent = 'Maximum retries reached. Please try again later or use email + password.';
    }
  }

  function parseResponse(res) {
    return res.text().then(function (t) {
      try { return JSON.parse(t); }
      catch (e) { throw new Error('The server returned an unexpected response (HTTP ' + res.status + '). Check the PHP error log.'); }
    });
  }

  function start() {
    if (busy) return;
    busy = true;
    session = new SafeTradeFace.FaceSession({
      video: videoEl,
      onStatus: function (m) { statusEl.textContent = m; statusEl.className = 'face-status-pill loading'; },
      onProgress: function (p) { progressEl.style.width = p + '%'; },
    });

    session.run()
      .then(function (result) {
        statusEl.className = 'face-status-pill loading';
        statusEl.textContent = 'Checking with server…';
        return fetch(API, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: mode === 'register' ? 'register_face' : 'login_with_face',
            embedding: result.descriptor,
            snapshot: mode === 'register' ? result.snapshot : null,
            csrf_token: window.SAFETRADE_CSRF || '',
          }),
        }).then(parseResponse);
      })
      .then(function (data) {
        if (!isOpen) return;
        if (data && data.ok) {
          statusEl.className = 'face-status-pill success';
          statusEl.textContent = mode === 'register' ? '✓ Face verified' : '✓ Signing you in…';
          progressEl.style.width = '100%';
          setTimeout(function () {
            close();
            if (callbacks && callbacks.onSuccess) callbacks.onSuccess(data);
          }, 800);
        } else {
          var msg = (data && data.message) || 'Face not recognized. Please try again.';
          // retrying can't fix these:
          var final = data && ['duplicate_face', 'account_blocked', 'rate_limited', 'no_pending_registration', 'csrf'].indexOf(data.error) !== -1;
          showError(msg, !final);
          if (callbacks && callbacks.onFail) callbacks.onFail(msg);
        }
      })
      .catch(function (err) {
        if (!isOpen) return; // user cancelled
        var msg = (err && err.message) || 'Something went wrong — please try again.';
        showError(msg, true);
        if (callbacks && callbacks.onFail) callbacks.onFail(msg);
      })
      .then(function () { busy = false; });
  }

  retryBtn.addEventListener('click', function () { retryCount++; reset(); start(); });
  cancelBtn.addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });

  window.openFaceModal = function (m, cb) {
    mode = m; callbacks = cb; retryCount = 0; isOpen = true;
    reset();
    subEl.textContent = m === 'register'
      ? "We'll ask you to turn your head or blink, then look straight at the camera."
      : "Center your face and follow the on-screen instructions to verify it's really you.";
    backdrop.classList.add('show');
    setTimeout(start, 250);
  };
})();
</script>