<?php
require_once __DIR__.'/../helpers.php';

// Test mini_ctx_append and mini_ctx_to_text
$ctx = [];
$ctx = mini_ctx_append($ctx, 'Hello', 'World');
if (count($ctx) !== 1) {
    throw new Exception('mini_ctx_append failed');
}
$text = mini_ctx_to_text($ctx);
if (strpos($text, 'Hello') === false || strpos($text, 'World') === false) {
    throw new Exception('mini_ctx_to_text failed');
}

// Test buttonize_links
$out = buttonize_links('Sprawdź https://example.com');
if (strpos($out, '<a') === false) {
    throw new Exception('buttonize_links failed');
}

// Test t_tokens
$tokens = t_tokens('Hej, cennik oraz koszt!');
if (!in_array('cennik', $tokens) || !in_array('koszt', $tokens)) {
    throw new Exception('t_tokens failed');
}

// Test snippet_from
$snip = snippet_from('Pierwsze zdanie. Drugie zdanie. Trzecie zdanie.', ['drugie'], 100);
if (strpos($snip, 'Drugie zdanie') === false) {
    throw new Exception('snippet_from failed');
}


echo "All tests passed\n";
?>
