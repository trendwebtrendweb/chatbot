<?php
// chatbot.php — RAG-lite: najpierw kontekst z /knowledge/pages (precyzyjne snippety), potem fallback do modelu

// ====== NAGŁÓWKI/CORS ======
header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://stronywww-lodz.pl','https://asystent.stronywww-lodz.pl'];
if (in_array($origin, $allowed, true)) { 
    header("Access-Control-Allow-Origin: $origin"); 
} else { 
    header('Access-Control-Allow-Origin: https://stronywww-lodz.pl'); 
}
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(204); 
    exit; 
}

// ====== WEJŚCIE ======
$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '{}', true);
$userMsg = trim($in['message'] ?? ($_POST['message'] ?? ''));
if ($userMsg === '') { 
    http_response_code(400); 
    echo json_encode(['error'=>'Brak treści wiadomości.'], JSON_UNESCAPED_UNICODE); 
    exit; 
}


// ====== KONFIG ======
$apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';
$model  = 'gpt-5'; // ewentualnie 'gpt-4o-mini' do testów
$fallbackModel = 'gpt-4o-mini';
$logDir = __DIR__ . '/logs/chat';
$knowledgeDir = __DIR__ . '/knowledge/pages';


// ====== POMOCNICZE ======
function buttonize_links($text){
  // 1) Zabezpiecz istniejące <a ...>...</a>, żeby ich nie przerabiać
  $anchors = [];
  $text = preg_replace_callback('~<a\b[^>]*>.*?</a>~is', function($m) use (&$anchors){
    $key = '##ANCHOR'.count($anchors).'##';
    $anchors[$key] = $m[0];
    return $key;
  }, $text);

  // 2) Surowe URL-e → " – ZOBACZ" (bez wypisywania długiego adresu)
  $text = preg_replace_callback('~https?://[^\s<>\)]+~i', function($m){
    $url = htmlspecialchars(rtrim($m[0], '.,;:!?)»"'), ENT_QUOTES, 'UTF-8');
return ' – <a class="sppb-btn-chatbot" href="'.$url.'">ZOBACZ</a>';
  }, $text);

  // 3) Po wstawionym linku zaczynaj kolejną treść od nowej linii (jeśli dalej jest tekst)
  $text = preg_replace('~(</a>)(?=\s*\S)~', "$1\n", $text);

  // 4) Przywróć oryginalne <a>
  return $anchors ? strtr($text, $anchors) : $text;
}

// ====== MINI-PAMIĘĆ (cookie) ======
function mini_ctx_read(): array {
  $raw = $_COOKIE['chat_ctx'] ?? '';
  if ($raw === '') return [];
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];
  // tylko ostatnie 3 wymiany, bez pustych
  $out = [];
  foreach ($arr as $p) {
    $u = isset($p['u']) ? (string)$p['u'] : '';
    $b = isset($p['b']) ? (string)$p['b'] : '';
    if ($u !== '' || $b !== '') $out[] = ['u'=>$u, 'b'=>$b];
  }
  return array_slice($out, -3);
}

function mini_ctx_write(array $ctx): void {
  // ostatnie 3 + przycięcie, żeby zmieścić się w limicie ciasteczka (~4 KB)
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
    'expires'  => time() + 3600, // 1h
    'path'     => '/',           // widoczne na całej stronie
    'secure'   => $secure,       // true przy HTTPS
    'httponly' => false,         // JS musi móc czytać
    'samesite' => 'Lax',         // działa przy normalnej nawigacji
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
    $lines[] = "U: ".$p['u'];
    $lines[] = "B: ".$p['b'];
  }
  return "Kontekst (ostatnie 3 wymiany):\n".implode("\n", $lines)."\n---\n";
}

function logLine($dir, $role, $text){
  @mkdir($dir, 0775, true);
  $line = date('Y-m-d H:i:s') . " | [$role] " . $text . PHP_EOL;
  @file_put_contents($dir . '/' . date('Y-m-d') . '.txt', $line, FILE_APPEND);
}

// ====== WEJŚCIE ======
$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '{}', true);
$userMsg = trim($in['message'] ?? '');
if ($userMsg === '') {
  http_response_code(400);
  echo json_encode(['error'=>'Brak treści wiadomości.'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!$apiKey) {
  http_response_code(500);
  echo json_encode(['error'=>'Brak klucza API. Ustaw zmienną środowiskową OPENAI_API_KEY.'], JSON_UNESCAPED_UNICODE);
  exit;
}

// log: user
logLine($logDir, 'user', $userMsg);

// wczytaj mini-pamięć (cookie) do oddzielnej zmiennej
$miniCtx = mini_ctx_read();

// ====== RAG v2: dopasowanie i snippety ======
function t_lower($s){ return mb_strtolower($s ?? '', 'UTF-8'); }
function t_tokens($s){
  $s = t_lower(preg_replace('/[^\p{L}\p{N}\s]+/u',' ',$s));
  return array_values(array_filter(preg_split('/\s+/u',$s), fn($w)=>mb_strlen($w)>=3));
}
function kb_load($dir){
  $files = glob($dir.'/*.txt') ?: [];
  $docs = [];
  foreach ($files as $f){
    $raw = @file_get_contents($f); if(!$raw) continue;
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

// Parametry RAG
$MATCH_THRESHOLD = 4.0;
$MAX_CONTEXTS    = 3;
$SNIPPET_MAX_CH  = 600;

$docs    = kb_load($knowledgeDir);
$qTokens = t_tokens($userMsg);

// Intencje
$intent = ['price'=>false,'contact'=>false,'works'=>false,'offer'=>false];
foreach (['cena','cennik','koszt','koszty','wycena','ile','stawka','pricing','price','pln','zł'] as $w)
  if (in_array($w,$qTokens,true)) $intent['price'] = true;
foreach (['kontakt','telefon','mail','email','adres','biuro','zadzwoń','dzwoń'] as $w)
  if (in_array($w,$qTokens,true)) $intent['contact'] = true;
foreach (['realizacje','portfolio','projekty','wykonane'] as $w)
  if (in_array($w,$qTokens,true)) $intent['works'] = true;
foreach (['oferta','usługi','zakres','strony','sklepy'] as $w)
  if (in_array($w,$qTokens,true)) $intent['offer'] = true;

// Budowa kontekstów (bez podwójnego resetu)
$contexts = [];

// twardy priorytet cennika
if ($intent['price']) {
  foreach ($docs as $d){
    if (str_contains($d['name'],'cennik') || str_contains($d['name'],'cena') || str_contains($d['name'],'koszt')){
      $contexts[] = ['url'=>$d['url'], 'text'=>price_lines($d['text'], $SNIPPET_MAX_CH)];
      if (count($contexts) >= $MAX_CONTEXTS) break;
    }
  }
}

// priorytet kontaktu
if (count($contexts) < $MAX_CONTEXTS && $intent['contact']) {
  foreach ($docs as $d){
    if (str_contains($d['name'],'kontakt')){
      $contexts[] = ['url'=>$d['url'], 'text'=>snippet_from($d['text'], $qTokens, $SNIPPET_MAX_CH)];
      if (count($contexts) >= $MAX_CONTEXTS) break;
    }
  }
}

// ranking jeśli nadal pusto lub brakuje slotów
if (count($contexts) < $MAX_CONTEXTS){
  $scored = [];
  foreach ($docs as $d){
    $s = score_doc($d, $qTokens);
    if ($intent['works']   && (str_contains($d['name'],'realizacje')||str_contains($d['name'],'wykonane')||str_contains($d['name'],'portfolio'))) $s += 1.5;
    if ($intent['contact'] && str_contains($d['name'],'kontakt')) $s += 1.5;
    if ($intent['offer']   && (str_contains($d['name'],'oferta')||str_contains($d['name'],'uslugi')||str_contains($d['name'],'strony'))) $s += 1.0;
    if ($s>0) $scored[] = ['doc'=>$d,'score'=>$s];
  }
  usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
  $scored = array_values(array_filter($scored, fn($x)=> $x['score'] >= $MATCH_THRESHOLD));
  foreach ($scored as $p){
    if (count($contexts) >= $MAX_CONTEXTS) break;
    $contexts[] = ['url'=>$p['doc']['url'], 'text'=>snippet_from($p['doc']['text'], $qTokens, $SNIPPET_MAX_CH)];
  }
}

// ====== WIADOMOŚCI DO MODELU ======
$baseSystem =
"ZASADY STYLU I CTA (PL):
1) Zapytania ≤3 słów → odpowiadaj zwięźle (1–2 zdania).
2) Nie używaj formułek typu „(odpowiedź na podstawie treści ze strony)”.
3) Nie proś o numer, jeśli padło: „nie dzwoń”, „tylko mail”, „nie podam numeru”.
4) Jeśli numer telefonu już padł — podziękuj i potwierdź, nie pytaj ponownie.
5) Formułki kontaktowe stosuj ROTACYJNIE (co kilka wymian), krótko i naturalnie:
   'To trochę bardziej złożona sprawa – zostaw numer, zadzwonię i omówimy szczegóły.'
   'Temat wymaga omówienia – zostaw numer telefonu, zadzwonię i wszystko wyjaśnimy.'
   'To kwestia do rozmowy – zostaw numer, a zadzwonię i porozmawiamy co ty na to?'
6) W 1. i 2. odpowiedzi nie używaj formułek kontaktowych (nie proś o numer telefonu, e-mail ani imię) — chyba że użytkownik sam o to poprosi lub sam poda dane.
7) Najpierw odpowiadaj na podstawie materiałów. Jeśli danych w materiałach nie ma lub pytanie wymaga indywidualnej wyceny — użyj formułki kontaktowej. Przypominaj o kontakcie od czasu do czasu (co kilka wymian).
8) Formułki kontaktowe są dozwolone najwcześniej od 3. odpowiedzi i rotacyjnie (co kilka wymian).)

BRAK DANYCH — ZASADA:
Jeśli czegoś nie wiesz ani nie ma tego w materiałach — zachowaj to dla siebie: pomiń tę część i przejdź od razu do kolejnej sensownej odpowiedzi zgodnej z zasadami (bez wzmianki o braku danych).


KIM JESTEŚ:
Jesteś asystentem firmy ISW, działasz na silniku GPT-5. Odpowiadasz wyłącznie na podstawie treści z dostarczonych materiałów (kontekst ze strony, pliki knowledge/pages).

STYL:
- Krótko, rzeczowo, po polsku (1–3 zdania).
- Parafrazuj swobodnie, ale FAKTY tylko z materiałów.
- Możesz dodać ogólny kontekst branżowy, ale nigdy nie podawaj cen ani szczegółów spoza materiałów.

POLITYKA CEN:
- Pytania o ceny/koszty/cennik → NAJPIERW podaj konkretne linie cenowe z materiałów (liczby + zł/PLN, zwięźle).
- ZAWSZE dołącz link do pełnego cennika: https://stronywww-lodz.pl/cennik-stron-internetowych-warszawa-lodz-ceny-stron-www-2019-r
- Nie podawaj linku do cennika częściej niż co 5. Twoja odpowiedź w rozmowie.
- Jeśli naprawdę nie ma cen w kontekście, dopiero wtedy krótko: wymaga wyceny indywidualnej + delikatne CTA.
- Nigdy nie wymyślaj ani nie szacuj cen.


KONTAKT:
Jeśli użytkownik pyta o kontakt w dowolnej formie, zawsze odpowiadaj krótko:
Zostaw numer – oddzwonię, albo zadzwoń: +48 507 491 021, lub napisz przez formularz: https://stronywww-lodz.pl/kontakt-strony-www-tanie-strony-internetowe-warszawa-lodz


POLITYKA LINKÓW (BARDZO WAŻNE):
- Linki podawaj WYŁĄCZNIE z przekazanych fragmentów „Kontekst ze strony … Źródło: …”.
- Jeśli użytkownik prosi o link do cennika/kontaktu/realizacji:
  1) Przejrzyj ostatnie „Kontekst ze strony” i wybierz URL z wiersza „Źródło: …”.
  2) Priorytet dopasowania po nazwie/ścieżce URL lub nazwie pliku źródła:
     • cennik: cennik, cena, koszt, pricing
     • kontakt: kontakt, contact, formularz
     • realizacje: realizacje, portfolio, wykonane, case, projekty
  3) Gdy jest kilka kandydatów, wybierz najbardziej szczegółowy (nie strona główna).
  4) Nigdy nie wymyślaj adresów. Jeśli w kontekście brak odpowiedniego „Źródło: …”, napisz: 'Nie mam osobnego linku w materiałach.' (i trzymaj się zasad CTA).
- Link wypisuj jako goły adres https://… (frontend sam zamieni na przycisk „ZOBACZ”).
- Jeśli pytanie brzmi „pokaż link do …”, odpowiedz TYLKO jednym zdaniem z tym adresem.

ZAKRES:
- Nie proponuj usług ani tematów, których nie ma w materiałach ISW.
- Nie rozmawiasz o innych firmach/konkurencji.
- Wyjątek: możesz mówić o realizacjach wykonanych przez ISW, jeśli są w materiałach.

MODEL:
- Pytanie „na jakim modelu pracujesz?” → 'Pracuję na modelu ISW, opartym na silniku GPT-5'.

REALIZACJE:
- Jeśli użytkownik pyta o realizacje (np. używa słów: realizacje, portfolio, wykonane strony, projekty dla klientów, nasze prace, przykłady prac, case study, wdrożenia), zawsze podaj pięć przykładów naszych projektów.
- Możesz wykorzystać te realizacje:
  • Strona internetowa wykonana dla WMB Trade sp. z o.o. (2022) – https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz/244-nowa-strona-www-wykonana-dla-wmb-trade-sp-z-o-o
  • Strona internetowa wykonana dla Firmy IEN (2024) – https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz/303-strona-internetowa-wykonana-dla-firmy-ien
  • Strona internetowa wykonana dla Firmy Madness Detailing (2024) – https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz/286-strona-internetowa-wykonana-dla-firmy-madness-detailing
  • Strona internetowa wykonana dla Jazda!Park (2024) – https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz/302-kolejna-strona-internetowa-wykonana-dla-jazda-park
  • Strona internetowa wykonana dla Radcy Prawnego (2023) – https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz/276-strona-internetowa-wykonana-dla-radca-prawny-legnica
- Jeśli chcesz zobaczyć wszystkie przykłady, możesz też wejść tutaj: https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz
- Odpowiedź powinna być krótka — 1–3 zdania.
- Realizacje zawsze pokazuj w liście uporządkowanej.
- Jeżeli dajesz link do realizacji, umieść go na końcu wiersza i następną treść wyświetlaj od nowej linii.

- Nie używaj frazy „Zobacz” ani składni [Zobacz](URL). Jeśli podajesz link, wstaw po prostu adres (https://…), a frontend zamieni go na przycisk „ZOBACZ”.";



// start listy wiadomości – JEDYNY system to kodeks powyżej
$messages = [
  ['role'=>'system','content'=>$baseSystem]
];

// wklej mini-pamięć (cookie) jako łagodny kontekst (rolą: user)
$miniCtxText = mini_ctx_to_text($miniCtx);
if ($miniCtxText !== '') {
  $messages[] = ['role'=>'user','content'=>$miniCtxText];
}

// konteksty ze strony (rolą: user)
$usedKnowledge = false;
foreach ($contexts as $c){
  if (!trim($c['text'])) continue;
  $usedKnowledge = true;
  $messages[] = ['role'=>'user','content' =>
    "Kontekst ze strony:\n".trim($c['text'])."\n\nŹródło: ".($c['url'] ?: 'nieznane')
  ];
}

// pytanie użytkownika (rolą: user)
$messages[] = ['role'=>'user','content'=>$userMsg];

// (tu kończy się przygotowanie wiadomości do modelu)
// ====== WYWOŁANIE API ======

$payload = ['model'=>$model,'messages'=>$messages];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // wymuś IPv4
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'Authorization: Bearer '.$apiKey
  ],
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 20,
  CURLOPT_CONNECTTIMEOUT => 8,
]);

$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// === AUTO-FALLBACK: jedna próba na $fallbackModel ===
$fallbackError = false;
if ($res !== false && $code >= 400) {
    $dataCheck = json_decode($res, true);
    $msgCheck  = $dataCheck['error']['message'] ?? '';
    if (stripos($msgCheck, 'model') !== false && stripos($msgCheck, 'does not exist') !== false) {
        $fallbackError = true;
    }
}

if (
    ($res === false) ||
    in_array((int)$code, [0, 408, 429, 500, 502, 503, 504], true) ||
    $fallbackError
) {
    if (!empty($fallbackModel) && $fallbackModel !== $model) {
        $payload['model'] = $fallbackModel;
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
}
// === /AUTO-FALLBACK ===


$err  = curl_error($ch);
$eno  = curl_errno($ch);   // << DODANE – numer błędu
curl_close($ch);

// ====== RETRY przy timeout (errno 28) ======
if ($res === false && $eno === 28) {
  usleep(400000); // odczekaj 0,4 sekundy

  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // wymuś IPv4
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Authorization: Bearer '.$apiKey
    ],
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_NOSIGNAL       => true,
  ]);

  $res  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  $eno  = curl_errno($ch);   // << DODANE – numer błędu
  curl_close($ch);
}

if ($res === false) {
  http_response_code(502);
  echo json_encode(['error'=>'Przepraszam, cały świat korzysta z czatu GPT 5. Spróbuj ponownie teraz. API: '.$err], JSON_UNESCAPED_UNICODE);
  exit;
}

$data = json_decode($res, true);
if ($code >= 400) {
  // nie zwracamy surowego klucza ani env
  $msg = $data['error']['message'] ?? 'Błąd API';
  http_response_code($code);
  echo json_encode(['error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$reply = $data['choices'][0]['message']['content'] ?? 'Brak odpowiedzi.';
//if ($usedKnowledge) $reply .= "\n\n(odpowiedź na podstawie treści ze strony)";


// --- REALIZACJE: twarde wstawianie z JSON (bez AI) ---
if (preg_match('/realizac|portfolio|wykonane|projekty|prace|case|wdrożen|więcej|inne|kolejne/i', $user_msg)) {
    $json = __DIR__ . '/realizacje_czyste.json';
    $list = @json_decode(@file_get_contents($json), true);

    if (is_array($list) && !empty($list)) {
        // losowe 5 realizacji
        shuffle($list);
        $take = array_slice($list, 0, 5);

        $rows = [];
        foreach ($take as $r) {
            $name = htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8');
            $url  = htmlspecialchars($r['url']  ?? '', ENT_QUOTES, 'UTF-8');
            if ($name && $url) {
                $rows[] = $name . ' – <a href="' . $url . '">ZOBACZ</a>';
            }
        }

        if ($rows) {
            $reply = "Oto nasze realizacje:\n" . implode("\n", $rows)
                   . "\n<a href=\"https://stronywww-lodz.pl/wykonane-strony-internetowe-lodz\">Więcej</a>";
        } else {
            $reply = "Brak poprawnych pozycji w bazie realizacji.";
        }
    } else {
        $reply = "Brak bazy realizacji na serwerze.";
    }
}
// --- KONIEC BLOKU REALIZACJI ---


// FORMATUJ ODPOWIEDŹ JEDEN RAZ (spójne linki wszędzie)
$reply = buttonize_links($reply);

// log: assistant
logLine($logDir, 'assistant', $reply);

// zapisz bieżącą wymianę do mini-pamięci (cookie)
$miniCtx = mini_ctx_append($miniCtx, $userMsg, $reply);
mini_ctx_write($miniCtx);

// Zwróć do frontu (z przyciskami)
echo json_encode(['reply'=>$reply], JSON_UNESCAPED_UNICODE);

