Optional smoke test of assets/js/id-face.js in headless Chromium with YOUR face-api models.
  npm i face-api.js@0.22.2 playwright && python3 make_images.py
  mkdir site && cp test_page.html site/t.html && cp node_modules/face-api.js/dist/face-api.min.js site/ && ln -s <path-to>/safetrade/assets site/assets
  (cd site && python3 -m http.server 8097 &)  &&  node run.js
Expected: id_with_face -> status ok (128 numbers), id_no_face -> no_face, distance between the two faces ~0.2.
