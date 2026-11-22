const { chromium } = require('playwright');
const [,, which] = process.argv;
(async () => {
  const b = await chromium.launch(); const p = await b.newPage();
  p.on('console', m => { if (m.type()==='error') console.log('console.error:', m.text().slice(0,160)); });
  await p.goto('http://127.0.0.1:8097/t.html');
  const out = {};
  for (const [name, file] of [['id_with_face','id_with_face.jpg'],['selfie_like','selfie_like.jpg'],['id_no_face','id_no_face.jpg']]) {
    await p.setInputFiles('#f', '' + __dirname + '/'+file);
    await p.waitForFunction(() => document.getElementById('id_face_status').value !== '', null, {timeout: 120000});
    out[name] = await p.evaluate(() => ({status: id_face_status.value, count: id_face_count.value, score: id_face_score.value, box: id_face_box.value, dlen: id_face_descriptor.value ? JSON.parse(id_face_descriptor.value).length : 0, msg: id_face_msg.textContent, d: id_face_descriptor.value}));
  }
  const a = JSON.parse(out.id_with_face.d), c = JSON.parse(out.selfie_like.d);
  const dist = Math.sqrt(a.reduce((s,v,i)=>s+(v-c[i])**2,0));
  for (const k in out) { delete out[k].d; }
  console.log(JSON.stringify(out, null, 1)); console.log('distance(ID-photo vs selfie-like crop) =', dist.toFixed(3));
  await b.close();
})();
