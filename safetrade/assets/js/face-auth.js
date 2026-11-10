/**
 * SafeTrade — browser-side face login / face-verified registration.
 *
 * FIX (why "Face not recognized" appeared even for a registered user):
 * the old version captured the 128-float descriptor immediately AFTER the
 * random head-turn / blink challenge — i.e. while the head was still turned
 * (left or right, different every time). The stored face and the login face
 * were therefore taken at different angles and often failed the distance
 * check. Now, after the challenge, the user is asked to look straight again
 * and the descriptor is the AVERAGE of several frontal frames, so register
 * and login always compare like with like.
 *
 * Only the descriptor (numbers) and an admin-review snapshot are sent.
 */
(function (global) {
  'use strict';

  let modelsLoaded = false;

  function getModelsUrl() {
    return (window.SAFETRADE_BASE || '') + '/assets/models';
  }

  const MODEL_NAMES = ['tiny_face_detector_model', 'face_landmark_68_model', 'face_recognition_model'];

  /** Verifies every model shard is complete before face-api touches it. */
  async function verifyModelFiles(modelsUrl) {
    for (const name of MODEL_NAMES) {
      let manifest;
      try {
        const r = await fetch(modelsUrl + '/' + name + '-weights_manifest.json', { cache: 'no-cache' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        manifest = await r.json();
      } catch (e) {
        throw new Error('Face model file missing on the server: ' + name + '-weights_manifest.json');
      }
      let expected = 0;
      manifest[0].weights.forEach(function (w) {
        const n = w.shape.reduce(function (a, b) { return a * b; }, 1);
        expected += n * (w.quantization ? 1 : 4);
      });
      let actual = 0;
      const bad = [];
      for (const path of manifest[0].paths) {
        try {
          const r = await fetch(modelsUrl + '/' + path, { cache: 'no-cache' });
          if (!r.ok) { bad.push(path + ' (HTTP ' + r.status + ')'); continue; }
          const len = (await r.arrayBuffer()).byteLength;
          actual += len;
          if (len < 1024 && manifest[0].paths.length > 1) bad.push(path + ' (only ' + len + ' bytes)');
        } catch (e) { bad.push(path + ' (unreachable)'); }
      }
      if (bad.length || actual !== expected) {
        throw new Error('Face model files are incomplete on the server (' + name + ': expected ' +
          expected + ' bytes, found ' + actual + (bad.length ? '; bad: ' + bad.join(', ') : '') +
          '). Re-copy the files from assets/models.');
      }
    }
  }

  async function loadModels() {
    if (modelsLoaded) return;
    const modelsUrl = getModelsUrl();
    await verifyModelFiles(modelsUrl);
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelsUrl),
      faceapi.nets.faceLandmark68Net.loadFromUri(modelsUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelsUrl),
    ]);
    modelsLoaded = true;
  }

  function detectOpts() {
    return new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.4 });
  }

  // ---- landmark geometry ----
  function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

  function eyeAspectRatio(pts) {
    const a = dist(pts[1], pts[5]);
    const b = dist(pts[2], pts[4]);
    const c = dist(pts[0], pts[3]);
    return (a + b) / (2 * c);
  }

  function headYawRatio(landmarks) {
    const jaw = landmarks.getJawOutline();
    const nose = landmarks.getNose();
    const noseTip = nose[nose.length - 1];
    const leftEdge = jaw[0], rightEdge = jaw[16];
    const total = dist(leftEdge, rightEdge) || 1;
    return dist(noseTip, leftEdge) / total;
  }

  // Yaw challenges are measured RELATIVE to the user's own neutral pose.
  const YAW_DELTA = 0.07;
  const FRONTAL_TOLERANCE = 0.035;   // how close to neutral counts as "looking straight"
  const CAPTURE_FRAMES = 5;          // descriptors averaged into the final one

  const CHALLENGES = {
    turn_left:  { label: 'Turn your head LEFT',  check: (d) => d > YAW_DELTA },
    turn_right: { label: 'Turn your head RIGHT', check: (d) => d < -YAW_DELTA },
    blink:      { label: 'Please blink' },
  };

  function pickChallengeSequence() {
    const pool = ['turn_left', 'turn_right', 'blink'];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = pool[i]; pool[i] = pool[j]; pool[j] = tmp;
    }
    return [pool[0]]; // one random action per attempt
  }

  /**
   * @param {Object} opts
   * @param {HTMLVideoElement} opts.video
   * @param {(msg:string)=>void} [opts.onStatus]
   * @param {(pct:number)=>void} [opts.onProgress]
   */
  function FaceSession(opts) {
    this.video = opts.video;
    this.onStatus = opts.onStatus || function () {};
    this.onProgress = opts.onProgress || function () {};
    this.stream = null;
    this._stop = false;
  }

  function assertCameraSupported() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      throw new Error(
        "Camera access isn't available on this connection. Browsers only allow camera " +
        "access over HTTPS or http://localhost — try opening SafeTrade at " +
        "http://localhost/safetrade (or 127.0.0.1) instead of an IP address."
      );
    }
  }

  FaceSession.prototype.startCamera = async function () {
    assertCameraSupported();
    this.stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 480 }, height: { ideal: 360 } },
      audio: false,
    });
    this.video.srcObject = this.stream;
    await this.video.play();
  };

  FaceSession.prototype.stopCamera = function () {
    this._stop = true;
    if (this.stream) {
      this.stream.getTracks().forEach(function (t) { t.stop(); });
      this.stream = null;
    }
  };

  /**
   * Detection -> liveness challenge -> look straight again -> capture an
   * averaged frontal descriptor. Resolves { descriptor, snapshot }.
   */
  FaceSession.prototype.run = async function () {
    this._stop = false;
    assertCameraSupported();
    this.onStatus('Loading face verification…');
    await loadModels();

    this.onStatus('Starting camera…');
    await this.startCamera();

    this.onStatus('Looking for a face…');
    await this._waitForSingleFace();
    this.onProgress(15);
    this.onStatus('Face detected ✓');

    this.onStatus('Look straight at the camera…');
    const base = await this._calibrate(8);

    const sequence = pickChallengeSequence();
    let done = 0;
    for (let i = 0; i < sequence.length; i++) {
      const step = sequence[i];
      if (step === 'blink') {
        this.onStatus('Please blink to verify');
        await this._waitForBlink(base);
      } else {
        this.onStatus(CHALLENGES[step].label);
        await this._waitForYaw(CHALLENGES[step].check, base);
      }
      done++;
      this.onProgress(Math.round(15 + (done / (sequence.length + 1)) * 55));
    }

    // KEY FIX: return to a straight pose before capturing the descriptor.
    this.onStatus('Now look straight at the camera…');
    await this._waitForFrontal(base);
    this.onProgress(80);

    this.onStatus('Hold still — capturing face…');
    const descriptor = await this._captureAveraged(base, CAPTURE_FRAMES);

    // Snapshot (admin review only) — grab before the camera stops.
    const snapshot = this._captureSnapshot();

    this.stopCamera();
    this.onProgress(100);
    this.onStatus('✓ Face captured');
    return { descriptor: descriptor, snapshot: snapshot };
  };

  FaceSession.prototype._captureSnapshot = function () {
    try {
      const video = this.video;
      const w = video.videoWidth || 320;
      const h = video.videoHeight || 240;
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      canvas.getContext('2d').drawImage(video, 0, 0, w, h);
      return canvas.toDataURL('image/jpeg', 0.85);
    } catch (e) {
      return null;
    }
  };

  FaceSession.prototype._waitForSingleFace = function (timeoutMs) {
    timeoutMs = timeoutMs || 15000;
    const self = this;
    return new Promise(function (resolve, reject) {
      const start = Date.now();
      (function tick() {
        if (self._stop) return reject(new Error('Cancelled'));
        if (Date.now() - start > timeoutMs) return reject(new Error('No face detected — please try again'));
        faceapi.detectSingleFace(self.video, detectOpts()).then(function (r) {
          if (r) return resolve(r);
          setTimeout(tick, 200);
        }).catch(function () { setTimeout(tick, 200); });
      })();
    });
  };

  FaceSession.prototype._loop = function (onFrame, timeoutMs, timeoutMsg) {
    const self = this;
    return new Promise(function (resolve, reject) {
      const start = Date.now();
      (function tick() {
        if (self._stop) return reject(new Error('Cancelled'));
        if (Date.now() - start > timeoutMs) return reject(new Error(timeoutMsg));
        faceapi.detectSingleFace(self.video, detectOpts()).withFaceLandmarks().then(function (r) {
          if (r && onFrame(r) === true) return resolve();
          setTimeout(tick, 0);
        }).catch(function () { setTimeout(tick, 60); });
      })();
    });
  };

  function median(a) {
    const b = a.slice().sort(function (x, y) { return x - y; });
    return b[Math.floor(b.length / 2)];
  }

  /** Collect ~n frames of the user's neutral pose (yaw + open-eye EAR). */
  FaceSession.prototype._calibrate = async function (n) {
    const yaws = [], ears = [];
    await this._loop(function (r) {
      const lm = r.landmarks;
      yaws.push(headYawRatio(lm));
      ears.push((eyeAspectRatio(lm.getLeftEye()) + eyeAspectRatio(lm.getRightEye())) / 2);
      return yaws.length >= n;
    }, 8000, 'No face detected — please try again');
    ears.sort(function (x, y) { return x - y; });
    return { yaw: median(yaws), ear: ears[Math.floor(ears.length * 0.75)] };
  };

  FaceSession.prototype._waitForYaw = async function (checkFn, base, timeoutMs) {
    let hits = 0;
    await this._loop(function (r) {
      const delta = headYawRatio(r.landmarks) - base.yaw;
      hits = checkFn(delta) ? hits + 1 : 0;
      return hits >= 2;
    }, timeoutMs || 25000, 'Head movement not detected — please turn your head a bit more and try again');
  };

  FaceSession.prototype._waitForBlink = async function (base, timeoutMs) {
    const closedBelow = Math.max(base.ear * 0.78, 0.12);
    const openAbove   = base.ear * 0.90;
    let sawClosed = false;
    await this._loop(function (r) {
      const lm = r.landmarks;
      const ear = (eyeAspectRatio(lm.getLeftEye()) + eyeAspectRatio(lm.getRightEye())) / 2;
      if (ear < closedBelow) sawClosed = true;
      else if (sawClosed && ear > openAbove) return true;
    }, timeoutMs || 25000, 'Blink not detected — please blink firmly and try again');
  };

  /** Wait until the head is back near the neutral pose for 3 frames in a row. */
  FaceSession.prototype._waitForFrontal = async function (base) {
    let hits = 0;
    await this._loop(function (r) {
      const delta = Math.abs(headYawRatio(r.landmarks) - base.yaw);
      hits = delta < FRONTAL_TOLERANCE ? hits + 1 : 0;
      return hits >= 3;
    }, 12000, 'Please look straight at the camera and try again');
  };

  /**
   * Captures `want` descriptors while the face is frontal and returns their
   * element-wise average (much more stable than a single frame).
   */
  FaceSession.prototype._captureAveraged = async function (base, want) {
    const got = [];
    const start = Date.now();
    while (got.length < want && Date.now() - start < 9000) {
      if (this._stop) throw new Error('Cancelled');
      let r = null;
      try {
        r = await faceapi.detectSingleFace(this.video, detectOpts()).withFaceLandmarks().withFaceDescriptor();
      } catch (e) { r = null; }
      if (r && Math.abs(headYawRatio(r.landmarks) - base.yaw) < FRONTAL_TOLERANCE * 1.5) {
        got.push(Array.from(r.descriptor));
      } else {
        await new Promise(function (res) { setTimeout(res, 60); });
      }
    }
    if (!got.length) {
      throw new Error('Could not capture a clear front-facing image — improve the lighting and try again');
    }
    const len = got[0].length;
    const avg = new Array(len).fill(0);
    got.forEach(function (d) { for (let i = 0; i < len; i++) avg[i] += d[i]; });
    for (let i = 0; i < len; i++) avg[i] /= got.length;
    return avg;
  };

  global.SafeTradeFace = { FaceSession: FaceSession, loadModels: loadModels };
})(window);
