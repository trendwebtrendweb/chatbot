<?php
function buttonize_links($text){
  $anchors = [];
  $text = preg_replace_callback('~<a\b[^>]*>.*?</a>~is', function($m) use (&$anchors){
    $key = '##ANCHOR'.count($anchors).'##';
    $anchors[$key] = $m[0];
    return $key;
  }, $text);
  $text = preg_replace_callback('~https?://[^\s<>\)]+~i', function($m){
    $url = htmlspecialchars(rtrim($m[0], '.,;:!?)»"'), ENT_QUOTES, 'UTF-8');
    return ' – <a class="sppb-btn-chatbot" href="'.$url.'">ZOBACZ</a>';
  }, $text);
  $text = preg_replace('~(</a>)(?=\s*\S)~', "$1\n", $text);
  return $anchors ? strtr($text, $anchors) : $text;
}

function mini_ctx_read(): array {
  $raw = $_COOKIE['chat_ctx'] ?? '';
  if ($raw === '') return [];
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];
  $out = [];
  foreach ($arr as $p) {
    $u = isset($p['u']) ? (string)$p['u'] : '';
    $b = isset($p['b']) ? (string)$p['b'] : '';
    if ($u !== '' || $b !== '') $out[] = ['u'=>$u, 'b'=>$b];
  }
  return array_slice($out, -3);
}

function mini_ctx_write(array $ctx): void {
  $ctx = array_slice($ctx, -3);
  $safe = [];
  foreach ($ctx as $p) {
    $u = mb_substr((string)($p['u'] ?? ''), 0, 300, 'UTF-8');
    $b = mb_substr((string)($p['b'] ?? ''), 0, 700, 'UTF-8');
    $safe[] = ['u'=>$u, 'b'=>$b];
  }
  $payload = json_encode($safe, JSON_UNESCAPED_UNICODE);
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  setcookie('chat_ctx', $payload, [
    'expires'  => time() + 3600,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}

function mini_ctx_append(array $ctx, string $u, string $b): array {
  $ctx[] = ['u'=>$u, 'b'=>$b];
  return array_slice($ctx, -3);
}

function mini_ctx_to_text(array $ctx): string {
  if (!$ctx) return '';
  $lines = [];
  foreach ($ctx as $p) {
    $lines[] = 'U: '.$p['u'];
    $lines[] = 'B: '.$p['b'];
  }
  return "Kontekst (ostatnie 3 wymiany):\n".implode("\n", $lines)."\n---\n";
}

function logLine(string $dir, string $role, string $text): void {
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    error_log("Nie można utworzyć katalogu logów: $dir");
    return;
  }
  $line = date('Y-m-d H:i:s') . " | [$role] " . $text . PHP_EOL;
  if (file_put_contents($dir . '/' . date('Y-m-d') . '.txt', $line, FILE_APPEND) === false) {
    error_log("Nie można zapisać logu do pliku w $dir");
  }
}


// === RAG helpers ===
function t_lower($s){ return mb_strtolower($s ?? '', 'UTF-8'); }

function t_tokens($s){
  $s = t_lower(preg_replace('/[^\p{L}\p{N}\s]+/u',' ',$s));
  return array_values(array_filter(preg_split('/\s+/u',$s), fn($w)=>mb_strlen($w)>=3));
}

function kb_load($dir){
  if (!is_dir($dir)) {
    error_log("Brak katalogu z bazą wiedzy: $dir");
    return [];
  }
  $files = glob($dir.'/*.txt');
  if ($files === false) {
    error_log("Nie można odczytać listy plików w $dir");
    return [];
  }
  $docs = [];
  foreach ($files as $f){
    if (!is_readable($f)) {
      error_log("Plik $f jest nieczytelny");
      continue;
    }
    $raw = file_get_contents($f);
    if ($raw === false) {
      error_log("Nie można odczytać pliku $f");
      continue;
    }
    $url = null; $body = $raw;
    if (preg_match('/^URL:\s*(.+)\RFetched:.*\R----\R/um',$raw,$m)) { $url=trim($m[1]); $body=substr($raw, strlen($m[0])); }
    elseif (preg_match('/^URL:\s*(.+)\R----\R/um',$raw,$m))        { $url=trim($m[1]); $body=substr($raw, strlen($m[0])); }
    $docs[] = [
      'name'=>t_lower(basename($f)),
      'url' =>$url,
      'text'=>$body,
      'intro'=>mb_substr($body,0,1200,'UTF-8'),
    ];
  }
  return $docs;
}

function score_doc($doc, $qTokens){
  $low = t_lower($doc['text']);
  $score = 0.0;
  foreach ($qTokens as $w){ $score += min(3, substr_count($low, $w)); }
  if (str_contains($doc['name'],'cennik') || str_contains($doc['name'],'cena') || str_contains($doc['name'],'koszt')) $score += 1.5;
  $introLow = t_lower($doc['intro']);
  foreach ($qTokens as $w){ if (mb_stripos($introLow,$w,0,'UTF-8')!==false){ $score += 0.25; break; } }
  return $score;
}

function split_sentences($txt){
  $txt = preg_replace('/\s+/u',' ', trim($txt));
  $parts = preg_split('/(?<=[\.\?\!])\s+/u', $txt);
  return array_values(array_filter($parts, fn($s)=> $s!=='' && !preg_match('/^\s*[-•*]/u',$s)));
}

function snippet_from($text, $qTokens, $maxCh){
  $sents = split_sentences($text);
  if (!$sents) return mb_substr($text,0,$maxCh,'UTF-8');
  $hit = 0;
  foreach ($sents as $i=>$s){
    $l = t_lower($s);
    foreach ($qTokens as $w){ if (mb_stripos($l,$w,0,'UTF-8')!==false){ $hit=$i; break 2; } }
  }
  $pick = $sents[$hit] ?? '';
  $next = ($sents[$hit+1] ?? '');
  $snip = trim($pick.($next ? ' '.$next : ''));
  if (mb_strlen($snip,'UTF-8') > $maxCh) $snip = mb_substr($snip,0,$maxCh,'UTF-8');
  return $snip;
}

function price_lines($text, $maxCh){
  $lines = preg_split('/\R/u', $text); $picked=[];
  foreach ($lines as $ln){
    if (preg_match('/(\d[\d\s\.,]{1,10})\s*(zł|pln)/iu',$ln) || preg_match('/\d{1,3}(\.\d{3}|\s\d{3})*(,\d{2})?/u',$ln)){
      $picked[] = trim($ln);
    }
    if (mb_strlen(implode(" ",$picked),'UTF-8') > $maxCh) break;
  }
  return $picked ? implode("\n", $picked) : snippet_from($text, [], $maxCh);
}
// === /RAG helpers ===
?>
