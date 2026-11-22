<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/face_recognition.php';
require_role('admin');
header('Content-Type: text/plain; charset=utf-8');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:');
echo "Camera-capable address (HTTPS or localhost): " . ($secure ? "YES" : "NO  <-- open http://localhost/safetrade instead of the IP") . "\n\n";

echo "Model files in assets/models:\n";
foreach (['tiny_face_detector_model-weights_manifest.json','tiny_face_detector_model-shard1',
          'face_landmark_68_model-weights_manifest.json','face_landmark_68_model-shard1',
          'face_recognition_model-weights_manifest.json','face_recognition_model-shard1','face_recognition_model-shard2'] as $f) {
    $p = __DIR__ . '/assets/models/' . $f;
    echo '  ' . (is_file($p) ? '[OK] ' . filesize($p) . ' bytes  ' : '[MISSING] ') . $f . "\n";
}

$c = db();
$n = (int) $c->query('SELECT COUNT(*) c FROM face_verifications WHERE embedding IS NOT NULL')->fetch_assoc()['c'];
$bad = 0;
$r = $c->query('SELECT embedding FROM face_verifications WHERE embedding IS NOT NULL');
while ($row = $r->fetch_assoc()) { if (!face_embedding_is_valid(json_decode((string) $row['embedding'], true))) $bad++; }
echo "\nStored face embeddings: $n (invalid: $bad)\n";
echo "Login threshold: " . FACE_MATCH_THRESHOLD . "  Duplicate threshold: " . FACE_DUPLICATE_THRESHOLD . "\n";