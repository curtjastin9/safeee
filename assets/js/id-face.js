/**
 * SafeTrade — ID step: read the face on the uploaded ID photo (browser side, face-api.js).
 *
 * Uses the same models as face login (assets/models) via SafeTradeFace.loadModels().
 * Writes hidden fields that register.php hands to the server-side screening:
 *   id_face_status      ok | no_face | error
 *   id_face_descriptor  JSON array of 128 floats (only when status = ok)
 *   id_face_score       detector confidence 0..1
 *   id_face_box         width of the face box in the ORIGINAL image's pixels
 *   id_face_count       how many faces were found (the largest one is used)
 *
 * Nothing here can block the form: on any failure it reports status=error and the
 * server simply sends the submission to an admin. The photo itself never leaves the
 * browser through this script (only the 128 numbers do).
 */
(function () {
  'use strict';

  function $(id) { return document.getElementById(id); }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve({ img: img, url: url }); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Could not read that image')); };
      img.src = url;   // browsers apply EXIF orientation to <img> by default
    });
  }

  async function describeFile(file) {
    var loaded = await loadImage(file);
    try {
      var img = loaded.img;
      // Work on a canvas capped at 1600px so huge phone photos don't freeze the tab.
      var scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
      var cv = document.createElement('canvas');
      cv.width = Math.round(img.naturalWidth * scale);
      cv.height = Math.round(img.naturalHeight * scale);
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);

      await window.SafeTradeFace.loadModels();
      // ID photos are small inside a big picture, so use a larger detector input and a lower threshold than the selfie flow.
      var opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 608, scoreThreshold: 0.3 });
      var all = await faceapi.detectAllFaces(cv, opts).withFaceLandmarks().withFaceDescriptors();
      if (!all.length) return { status: 'no_face', count: 0 };
      all.sort(function (a, b) { return b.detection.box.width * b.detection.box.height - a.detection.box.width * a.detection.box.height; });
      var best = all[0];
      return {
        status: 'ok',
        count: all.length,
        descriptor: Array.from(best.descriptor),
        score: best.detection.score,
        box: Math.round(best.detection.box.width / scale),
      };
    } finally {
      URL.revokeObjectURL(loaded.url);
    }
  }

  function init() {
    var form = $('id-form');
    var input = form && form.querySelector('input[name="id_front"]');
    if (!form || !input) return;

    var f = {
      status: $('id_face_status'), desc: $('id_face_descriptor'), score: $('id_face_score'),
      box: $('id_face_box'), count: $('id_face_count'),
    };
    var msg = $('id_face_msg');
    var pending = null;

    function say(text, color) { if (msg) { msg.textContent = text; msg.style.color = color || ''; } }
    function reset() { f.status.value = ''; f.desc.value = ''; f.score.value = ''; f.box.value = ''; f.count.value = ''; }

    input.addEventListener('change', function () {
      reset();
      var file = input.files && input.files[0];
      if (!file) { say(''); pending = null; return; }
      if (typeof faceapi === 'undefined' || !window.SafeTradeFace) {
        f.status.value = 'error';
        say('Face check unavailable in this browser — an admin will compare the photos.', '#B7791F');
        pending = null;
        return;
      }
      say('Looking for the face on your ID…');
      pending = describeFile(file).then(function (r) {
        f.status.value = r.status;
        f.count.value = r.count || 0;
        if (r.status === 'ok') {
          f.desc.value = JSON.stringify(r.descriptor);
          f.score.value = r.score.toFixed(3);
          f.box.value = r.box;
          say('✓ Face found on the ID' + (r.count > 1 ? ' (using the largest of ' + r.count + ')' : '') + '.', '#155943');
        } else {
          say('No face found on this photo. Make sure the photo on your ID is visible and sharp — you can still submit, but an admin will review it.', '#B7791F');
        }
      }).catch(function (e) {
        f.status.value = 'error';
        say('Could not check the face (' + (e && e.message ? e.message : 'error') + '). You can still submit; an admin will compare the photos.', '#B7791F');
      }).then(function () { pending = null; });
    });

    // If the user presses Submit while the face is still being read, wait for it.
    form.addEventListener('submit', function (ev) {
      if (!pending) return;
      ev.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.textContent = 'Checking your ID photo…'; }
      pending.then(function () { form.submit(); });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
