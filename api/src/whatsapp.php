<?php
// WhatsApp (Meta Cloud API) bot. Linked users run their store from chat: sell, restock, expenses, debts, reports, photos, questions.
//
// Flow: webhook → dedupe/rate-limit → (LINK code | linked? ) → interpret text with AI → build a plan.
// Anything that changes data is summarised and waits for YES / NO. Read-only requests answer immediately.

// ───────── Config & transport ─────────

function wa_api_base()
{
    return rtrim(cfg('wa_api_base') ?: 'https://graph.facebook.com/v21.0', '/');
}

function wa_ready()
{
    return (bool) (cfg('wa_token') && cfg('wa_phone_number_id'));
}

function wa_norm_number($n)
{
    return preg_replace('/\D+/', '', (string) $n);
}

function wa_send($to, $text)
{
    if (!wa_ready()) {
        error_log('[store-ai wa] wa_token / wa_phone_number_id not set; cannot reply');
        return false;
    }
    try {
        ai_http(wa_api_base() . '/' . cfg('wa_phone_number_id') . '/messages', ['Authorization: Bearer ' . cfg('wa_token')], [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => mb_substr($text, 0, 3900)],
        ]);
        return true;
    } catch (HttpError $e) {
        error_log('[store-ai wa] send failed: ' . $e->getMessage());
        return false;
    }
}

function wa_http_get($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . cfg('wa_token')],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// Returns the image bytes for a WhatsApp media id.
function wa_download_media($mediaId)
{
    list($code, $body) = wa_http_get(wa_api_base() . '/' . rawurlencode($mediaId));
    $meta = json_decode((string) $body, true);
    if ($code >= 400 || empty($meta['url'])) {
        abort(502, 'Could not fetch the photo from WhatsApp');
    }
    list($code2, $bytes) = wa_http_get($meta['url']);
    if ($code2 >= 400 || $bytes === false || $bytes === '') {
        abort(502, 'Could not download the photo from WhatsApp');
    }
    if (strlen($bytes) > 5 * 1024 * 1024) {
        abort(413, 'That photo is larger than 5MB');
    }
    return $bytes;
}

// ───────── Formatting ─────────

function wa_money($store, $n)
{
    $sym = ['NGN' => '₦', 'GHS' => '₵', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'ZAR' => 'R', 'KES' => 'KSh '];
    $n = (float) $n;
    $s = number_format(abs($n), abs($n - round($n)) < 0.005 ? 0 : 2);
    $c = $store['currency'];
    return ($n < 0 ? '-' : '') . (isset($sym[$c]) ? $sym[$c] : $c . ' ') . $s;
}

// The analyst answers in **bold**; WhatsApp bold is *bold*.
function wa_markdown($text)
{
    return preg_replace('/\*\*(.+?)\*\*/s', '*$1*', preg_replace('/^#+\s*/m', '', $text));
}

function wa_help()
{
    return "👋 *Store-AI on WhatsApp*\n\n"
        . "Just type what happened:\n"
        . "• _sold 2 indomie and 1 peak milk, cash_\n"
        . "• _sold 3 bread to Mrs Bola on credit, paid 1000_\n"
        . "• _received 20 cartons of Peak milk, cost 600_\n"
        . "• _spent 2500 on transport_\n"
        . "• _Mrs Bola paid 500_ / _I owe Dangote 20k_\n"
        . "• _new product: Sardine, cost 350, price 500, qty 12_\n"
        . "📷 Send a *photo* of your stock to add it automatically.\n\n"
        . "Ask anything: _how much did I make today?_ _what should I restock?_\n\n"
        . "Shortcuts: *TODAY* · *STOCK* · *DEBTS* · *HELP*\n"
        . "I always ask you to confirm before saving. Reply *YES* or *NO*.";
}

// ───────── Fuzzy matching ─────────

function wa_norm_name($s)
{
    $s = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s)));
    $out = [];
    foreach (explode(' ', $s) as $t) {
        if ($t !== '') {
            $out[] = (strlen($t) > 3 && substr($t, -1) === 's') ? substr($t, 0, -1) : $t; // peak milks → peak milk
        }
    }
    return implode(' ', $out);
}

// → ['one'|'many'|'none', rows[]]
function wa_find($rows, $query, $key = 'name')
{
    $q = wa_norm_name($query);
    if ($q === '') {
        return ['none', []];
    }
    $tokens = explode(' ', $q);
    $all = [];
    $fuzzy = [];
    foreach ($rows as $r) {
        $n = wa_norm_name($r[$key]);
        if ($n === $q) {
            return ['one', [$r]];
        }
        $ok = true;
        foreach ($tokens as $t) {
            if (strpos($n, $t) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $all[] = $r;
        } else {
            similar_text($n, $q, $pct);
            if ($pct >= 78) {
                $fuzzy[] = [$pct, $r];
            }
        }
    }
    if (count($all) === 1) {
        return ['one', $all];
    }
    if (count($all) > 1) {
        return ['many', array_slice($all, 0, 4)];
    }
    if ($fuzzy) {
        usort($fuzzy, function ($a, $b) { return $b[0] <=> $a[0]; });
        if (count($fuzzy) === 1 || $fuzzy[0][0] - $fuzzy[1][0] >= 8) {
            return ['one', [$fuzzy[0][1]]];
        }
        return ['many', array_map(function ($f) { return $f[1]; }, array_slice($fuzzy, 0, 3))];
    }
    return ['none', []];
}

// Resolve [{name, quantity, ...}] to catalog rows. → [lines[], problems[]]
function wa_resolve_items($products, $items)
{
    $lines = [];
    $problems = [];
    foreach (is_array($items) ? $items : [] as $it) {
        $name = isset($it['name']) ? trim((string) $it['name']) : '';
        $qty = (int) (isset($it['quantity']) ? $it['quantity'] : 0);
        if ($name === '') {
            continue;
        }
        if ($qty < 1) {
            $problems[] = '“' . $name . '” — how many?';
            continue;
        }
        list($kind, $found) = wa_find($products, $name);
        if ($kind === 'one') {
            $lines[] = ['p' => $found[0], 'qty' => $qty, 'raw' => $it];
        } elseif ($kind === 'many') {
            $problems[] = '“' . $name . '” — which one? ' . implode(' / ', array_map(function ($r) { return $r['name']; }, $found));
        } else {
            $problems[] = '“' . $name . '” isn’t in your stock list';
        }
    }
    return [$lines, $problems];
}

function wa_problem_reply($problems, $hint = '')
{
    return "I couldn’t do that yet:\n• " . implode("\n• ", $problems) . ($hint ? "\n\n" . $hint : '');
}

// ───────── AI: understand the message ─────────

function wa_interpret($store, $text, $products, $debts)
{
    $catalog = [];
    foreach (array_slice($products, 0, 200) as $p) {
        $catalog[] = $p['name'];
    }
    $parties = array_values(array_unique(array_map(function ($d) { return $d['party_name']; }, $debts)));

    $system = "You are the command parser for a WhatsApp assistant used by a small {$store['type_name']} shop. Today is " . date('Y-m-d') . ". Currency: {$store['currency']}.\n"
        . "Convert the user's message into ONE JSON object and nothing else. Pick the intent:\n"
        . '- {"intent":"sale","items":[{"name":str,"quantity":int,"unit_price":number|null}],"payment_mode":"cash|card|transfer|credit","customer_name":str|null,"amount_paid":number|null}  (default cash; "on credit"/"owes me"/"pay later" = credit; amount_paid only if the customer paid part now)' . "\n"
        . '- {"intent":"restock","items":[{"name":str,"quantity":int,"cost_price":number|null}]}  (received / bought / added stock)' . "\n"
        . '- {"intent":"stock_remove","items":[{"name":str,"quantity":int}]}  (damaged, lost, expired)' . "\n"
        . '- {"intent":"new_product","name":str,"cost_price":number|null,"sale_price":number|null,"quantity":int|null}' . "\n"
        . '- {"intent":"set_price","name":str,"cost_price":number|null,"sale_price":number|null}' . "\n"
        . '- {"intent":"expense","category":str,"amount":number,"note":str|null}' . "\n"
        . '- {"intent":"debt_add","direction":"owed_to_me|i_owe","party_name":str,"amount":number,"due_date":"YYYY-MM-DD"|null,"note":str|null}' . "\n"
        . '- {"intent":"debt_pay","direction":"owed_to_me|i_owe|null","party_name":str,"amount":number|null,"payment_mode":"cash|card|transfer"}  (amount null = the full balance)' . "\n"
        . '- {"intent":"stock_check","name":str|null}' . "\n"
        . '- {"intent":"report","period":"today|yesterday|week|month|last_month"}' . "\n"
        . '- {"intent":"debts_list"}' . "\n"
        . '- {"intent":"question"}  (any other question about sales, profit, stock or advice)' . "\n"
        . '- {"intent":"help"}  (greeting or asking what you can do)' . "\n"
        . '- {"intent":"unknown"}  (unrelated to the shop)' . "\n"
        . "Rules: 20k = 20000, 1.5k = 1500. Use a catalog name when the user's wording clearly means it, otherwise keep their words. Never invent products, quantities, prices or amounts the user did not say. "
        . "\"I owe X\" / \"I borrowed\" = i_owe; \"X owes me\" = owed_to_me; \"X paid\" with an existing debtor = debt_pay.\n"
        . 'CATALOG: ' . json_encode($catalog, JSON_UNESCAPED_UNICODE) . "\n"
        . 'DEBT PARTIES: ' . json_encode($parties, JSON_UNESCAPED_UNICODE);

    $reply = ai_chat($system, [['role' => 'user', 'content' => $text]], 700);
    $parsed = parse_ai_json($reply);
    return is_array($parsed) && isset($parsed['intent']) ? $parsed : ['intent' => 'unknown'];
}

// ───────── Plans (what we'll do, plus the summary shown to the user) ─────────
// Each returns [replyText, pendingPayloadOrNull].

function wa_confirm_footer()
{
    return "\n\nReply *YES* to confirm or *NO* to cancel.";
}

function wa_plan_sale($store, $d, $products)
{
    list($lines, $problems) = wa_resolve_items($products, isset($d['items']) ? $d['items'] : []);
    if (!$lines && !$problems) {
        return ["Which products did you sell? e.g. _sold 2 indomie and 1 peak milk_", null];
    }
    // stock check
    $need = [];
    foreach ($lines as $l) {
        $id = $l['p']['id'];
        $need[$id] = (isset($need[$id]) ? $need[$id] : 0) + $l['qty'];
        if ($need[$id] > (int) $l['p']['quantity']) {
            $problems[] = $l['p']['name'] . ' — only ' . (int) $l['p']['quantity'] . ' in stock';
        }
    }
    if ($problems) {
        return [wa_problem_reply($problems), null];
    }

    $mode = isset($d['payment_mode']) && in_array($d['payment_mode'], PAY_MODES, true) ? $d['payment_mode'] : 'cash';
    $customer = !empty($d['customer_name']) ? trim((string) $d['customer_name']) : null;
    $items = [];
    $out = "🧾 *Record this sale?*\n";
    $total = 0.0;
    foreach ($lines as $l) {
        $price = isset($l['raw']['unit_price']) && is_numeric($l['raw']['unit_price']) && $l['raw']['unit_price'] > 0 ? (float) $l['raw']['unit_price'] : (float) $l['p']['sale_price'];
        $items[] = ['product_id' => (int) $l['p']['id'], 'quantity' => $l['qty'], 'unit_price' => $price];
        $total += $l['qty'] * $price;
        $out .= '• ' . $l['qty'] . ' × ' . $l['p']['name'] . ' @ ' . wa_money($store, $price) . ' = ' . wa_money($store, $l['qty'] * $price) . "\n";
    }
    $paid = isset($d['amount_paid']) && is_numeric($d['amount_paid']) ? max(0, min((float) $d['amount_paid'], $total)) : null;
    $effectivePaid = $paid === null ? ($mode === 'credit' ? 0.0 : $total) : $paid;
    $balance = round($total - $effectivePaid, 2);
    if ($balance > 0 && !$customer) {
        return ['Who is the customer for the unpaid ' . wa_money($store, $balance) . '? Send the sale again with the name, e.g. _… to Mrs Bola_.', null];
    }
    $out .= "\n*Total: " . wa_money($store, $total) . '* · ' . ucfirst($mode);
    if ($customer) {
        $out .= "\nCustomer: " . $customer;
    }
    if ($balance > 0) {
        $out .= "\nPaid now: " . wa_money($store, $effectivePaid) . ' · *Owes: ' . wa_money($store, $balance) . '*';
    }
    return [$out . wa_confirm_footer(), ['type' => 'sale', 'items' => $items, 'mode' => $mode, 'customer' => $customer, 'amount_paid' => $paid]];
}

function wa_plan_stock($store, $d, $products, $remove)
{
    list($lines, $problems) = wa_resolve_items($products, isset($d['items']) ? $d['items'] : []);
    if (!$lines && !$problems) {
        return ['Which products and how many? e.g. _received 20 peak milk_', null];
    }
    foreach ($lines as $l) {
        if ($remove && $l['qty'] > (int) $l['p']['quantity']) {
            $problems[] = $l['p']['name'] . ' — only ' . (int) $l['p']['quantity'] . ' in stock';
        }
    }
    if ($problems) {
        return [wa_problem_reply($problems, $remove ? '' : 'New product? Say e.g. _new product: Sardine, cost 350, price 500, qty 12_'), null];
    }
    $items = [];
    $out = $remove ? "➖ *Remove this stock?*\n" : "➕ *Add this stock?*\n";
    foreach ($lines as $l) {
        $cost = !$remove && isset($l['raw']['cost_price']) && is_numeric($l['raw']['cost_price']) && $l['raw']['cost_price'] > 0 ? (float) $l['raw']['cost_price'] : null;
        $items[] = ['product_id' => (int) $l['p']['id'], 'change' => $remove ? -$l['qty'] : $l['qty'], 'cost' => $cost];
        $out .= '• ' . ($remove ? '−' : '+') . $l['qty'] . ' ' . $l['p']['name'] . ' (now ' . ((int) $l['p']['quantity'] + ($remove ? -$l['qty'] : $l['qty'])) . ')'
            . ($cost ? ' · new cost ' . wa_money($store, $cost) : '') . "\n";
    }
    return [rtrim($out) . wa_confirm_footer(), ['type' => 'stock', 'items' => $items, 'remove' => $remove]];
}

function wa_plan_new_product($store, $d, $products)
{
    $name = isset($d['name']) ? trim((string) $d['name']) : '';
    if ($name === '') {
        return ['What is the product called? e.g. _new product: Sardine, cost 350, price 500, qty 12_', null];
    }
    list($kind, $found) = wa_find($products, $name);
    if ($kind === 'one' && wa_norm_name($found[0]['name']) === wa_norm_name($name)) {
        return ['“' . $found[0]['name'] . '” already exists. To add stock say _received 10 ' . $found[0]['name'] . '_; to change prices say _set price ' . $found[0]['name'] . ' to 500_.', null];
    }
    $cost = isset($d['cost_price']) && is_numeric($d['cost_price']) ? max(0, (float) $d['cost_price']) : 0.0;
    $price = isset($d['sale_price']) && is_numeric($d['sale_price']) ? max(0, (float) $d['sale_price']) : 0.0;
    $qty = isset($d['quantity']) && is_numeric($d['quantity']) ? max(0, (int) $d['quantity']) : 0;
    $out = "🆕 *Add new product?*\n• " . $name . "\n• Cost " . wa_money($store, $cost) . ' · Sells ' . wa_money($store, $price) . "\n• Quantity: " . $qty;
    if ($price <= 0) {
        $out .= "\n⚠️ No selling price yet; you can set it later.";
    }
    return [$out . wa_confirm_footer(), ['type' => 'new_product', 'name' => $name, 'cost' => $cost, 'price' => $price, 'qty' => $qty]];
}

function wa_plan_set_price($store, $d, $products)
{
    list($kind, $found) = wa_find($products, isset($d['name']) ? $d['name'] : '');
    if ($kind !== 'one') {
        return [$kind === 'many' ? 'Which one? ' . implode(' / ', array_map(function ($r) { return $r['name']; }, $found)) : 'Which product’s price do you want to change?', null];
    }
    $p = $found[0];
    $cost = isset($d['cost_price']) && is_numeric($d['cost_price']) && $d['cost_price'] >= 0 ? (float) $d['cost_price'] : null;
    $price = isset($d['sale_price']) && is_numeric($d['sale_price']) && $d['sale_price'] >= 0 ? (float) $d['sale_price'] : null;
    if ($cost === null && $price === null) {
        return ['What should the new price be? e.g. _set price ' . $p['name'] . ' to 500_', null];
    }
    $out = "💲 *Update price for " . $p['name'] . "?*";
    if ($cost !== null) {
        $out .= "\n• Cost: " . wa_money($store, $p['cost_price']) . ' → ' . wa_money($store, $cost);
    }
    if ($price !== null) {
        $out .= "\n• Selling: " . wa_money($store, $p['sale_price']) . ' → ' . wa_money($store, $price);
    }
    return [$out . wa_confirm_footer(), ['type' => 'set_price', 'product_id' => (int) $p['id'], 'cost' => $cost, 'price' => $price]];
}

function wa_plan_expense($store, $d)
{
    $amount = isset($d['amount']) && is_numeric($d['amount']) ? (float) $d['amount'] : 0;
    if ($amount <= 0) {
        return ['How much was the expense? e.g. _spent 2500 on transport_', null];
    }
    $cat = !empty($d['category']) ? ucfirst(trim((string) $d['category'])) : 'General';
    $note = !empty($d['note']) ? trim((string) $d['note']) : null;
    return ["🧮 *Record this expense?*\n• " . $cat . ': ' . wa_money($store, $amount) . ($note ? "\n• " . $note : '') . wa_confirm_footer(),
        ['type' => 'expense', 'category' => $cat, 'amount' => $amount, 'note' => $note]];
}

function wa_plan_debt_add($store, $d)
{
    $dir = isset($d['direction']) && in_array($d['direction'], ['owed_to_me', 'i_owe'], true) ? $d['direction'] : null;
    $party = !empty($d['party_name']) ? trim((string) $d['party_name']) : '';
    $amount = isset($d['amount']) && is_numeric($d['amount']) ? (float) $d['amount'] : 0;
    if (!$dir || $party === '' || $amount <= 0) {
        return ['Tell me who, how much, and which way, e.g. _Mrs Bola owes me 5000_ or _I owe Dangote 20k_.', null];
    }
    $due = isset($d['due_date']) ? valid_date($d['due_date'], null) : null;
    $note = !empty($d['note']) ? trim((string) $d['note']) : null;
    $out = ($dir === 'owed_to_me' ? "📥 *Record: " . $party . ' owes you ' : "📤 *Record: you owe " . $party . ' ') . wa_money($store, $amount) . '*';
    if ($due) {
        $out .= "\nDue: " . $due;
    }
    if ($note) {
        $out .= "\n" . $note;
    }
    return [$out . wa_confirm_footer(), ['type' => 'debt_add', 'direction' => $dir, 'party' => $party, 'amount' => $amount, 'due' => $due, 'note' => $note]];
}

function wa_plan_debt_pay($store, $d, $sid)
{
    $party = !empty($d['party_name']) ? trim((string) $d['party_name']) : '';
    if ($party === '') {
        return ['Whose payment? e.g. _Mrs Bola paid 500_', null];
    }
    $dir = isset($d['direction']) && in_array($d['direction'], ['owed_to_me', 'i_owe'], true) ? $d['direction'] : null;
    $open = rows("SELECT * FROM debts WHERE store_id = ? AND status <> 'settled' " . ($dir ? 'AND direction = ? ' : '') . 'ORDER BY id', $dir ? [$sid, $dir] : [$sid]);
    list($kind, $found) = wa_find($open, $party, 'party_name');
    if ($kind === 'none') {
        return ['I can’t find an open debt for “' . $party . '”. Say _debts_ to see them all.', null];
    }
    $names = array_values(array_unique(array_map(function ($r) { return $r['party_name'] . ($r['direction'] === 'owed_to_me' ? ' (owes you)' : ' (you owe)'); }, $found)));
    $sameParty = count(array_unique(array_map(function ($r) { return $r['party_name'] . '|' . $r['direction']; }, $found))) === 1;
    if ($kind === 'many' && !$sameParty) {
        return ['Which one? ' . implode(' / ', $names), null];
    }
    // every open debt of that party+direction, oldest first
    $target = $found[0];
    $debts = array_values(array_filter($open, function ($r) use ($target) {
        return $r['party_name'] === $target['party_name'] && $r['direction'] === $target['direction'];
    }));
    $balance = array_sum(array_map(function ($r) { return $r['amount'] - $r['amount_paid']; }, $debts));
    $amount = isset($d['amount']) && is_numeric($d['amount']) && $d['amount'] > 0 ? (float) $d['amount'] : $balance;
    if ($amount > $balance + 0.001) {
        return ['That’s more than the ' . wa_money($store, $balance) . ' outstanding for ' . $target['party_name'] . '.', null];
    }
    $mode = isset($d['payment_mode']) && in_array($d['payment_mode'], ['cash', 'card', 'transfer'], true) ? $d['payment_mode'] : 'cash';
    $alloc = [];
    $left = $amount;
    foreach ($debts as $db) {
        if ($left <= 0.001) {
            break;
        }
        $take = round(min($left, $db['amount'] - $db['amount_paid']), 2);
        $alloc[] = [(int) $db['id'], $take];
        $left -= $take;
    }
    $in = $target['direction'] === 'owed_to_me';
    $out = ($in ? '💰 *' . $target['party_name'] . ' pays you ' : '💸 *You pay ' . $target['party_name'] . ' ') . wa_money($store, $amount) . '* (' . $mode . ")\nBalance after: " . wa_money($store, $balance - $amount);
    return [$out . wa_confirm_footer(), ['type' => 'debt_pay', 'alloc' => $alloc, 'mode' => $mode, 'party' => $target['party_name'], 'in' => $in]];
}

// ───────── Read-only answers ─────────

function wa_answer_stock($store, $d, $products)
{
    $name = !empty($d['name']) ? trim((string) $d['name']) : '';
    if ($name !== '') {
        list($kind, $found) = wa_find($products, $name);
        if ($kind === 'none') {
            return '“' . $name . '” isn’t in your stock list.';
        }
        $out = '';
        foreach ($found as $p) {
            $out .= '📦 *' . $p['name'] . '*: ' . (int) $p['quantity'] . ' in stock · sells ' . wa_money($store, $p['sale_price']) . ' (cost ' . wa_money($store, $p['cost_price']) . ")\n";
        }
        return rtrim($out);
    }
    $snap = snapshot_data($store['id']);
    $out = "📦 *Stock*: " . $snap['inventory']['products'] . ' products, ' . $snap['inventory']['units'] . " units\nValue: " . wa_money($store, $snap['inventory']['cost_value']) . ' at cost · ' . wa_money($store, $snap['inventory']['retail_value']) . ' at retail';
    if ($snap['low_stock']) {
        $out .= "\n\n⚠️ *Low / out of stock*";
        foreach (array_slice($snap['low_stock'], 0, 15) as $p) {
            $out .= "\n• " . $p['name'] . ': ' . (int) $p['quantity'];
        }
    } else {
        $out .= "\n\n✅ Nothing is running low.";
    }
    return $out;
}

function wa_answer_report($store, $d)
{
    $period = isset($d['period']) ? $d['period'] : 'today';
    $today = date('Y-m-d');
    switch ($period) {
        case 'yesterday':
            $from = $to = date('Y-m-d', strtotime('-1 day'));
            $label = 'Yesterday';
            break;
        case 'week':
            $from = date('Y-m-d', strtotime('-6 days'));
            $to = $today;
            $label = 'Last 7 days';
            break;
        case 'month':
            $from = date('Y-m-01');
            $to = $today;
            $label = 'This month';
            break;
        case 'last_month':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to = date('Y-m-t', strtotime('first day of last month'));
            $label = 'Last month';
            break;
        default:
            $from = $to = $today;
            $label = 'Today';
    }
    $r = report_data($store['id'], $from, $to);
    $out = "📊 *" . $label . "*\nSales: " . wa_money($store, $r['revenue']) . ' (' . $r['sales_count'] . ' sale' . ($r['sales_count'] == 1 ? '' : 's') . ")\n"
        . 'Gross profit: ' . wa_money($store, $r['gross_profit']) . "\nExpenses: " . wa_money($store, $r['expenses']) . "\n*Net profit: " . wa_money($store, $r['net_profit']) . '*';
    if ($r['top_products']) {
        $out .= "\n\n🏆 *Top sellers*";
        foreach (array_slice($r['top_products'], 0, 3) as $p) {
            $out .= "\n• " . $p['name'] . ' — ' . $p['qty'] . ' sold, ' . wa_money($store, $p['revenue']);
        }
    }
    return $out;
}

function wa_answer_debts($store)
{
    $rows = rows("SELECT direction, party_name, amount - amount_paid AS bal, due_date FROM debts WHERE store_id = ? AND status <> 'settled' ORDER BY due_date IS NULL, due_date, id", [$store['id']]);
    if (!$rows) {
        return '✅ No open debts. Nobody owes you and you owe nobody.';
    }
    $groups = ['owed_to_me' => [], 'i_owe' => []];
    foreach ($rows as $r) {
        $groups[$r['direction']][] = $r;
    }
    $out = '';
    foreach (['owed_to_me' => '📥 *Owed to you*', 'i_owe' => '📤 *You owe*'] as $k => $title) {
        if (!$groups[$k]) {
            continue;
        }
        $out .= $title . ' — ' . wa_money($store, array_sum(array_column($groups[$k], 'bal'))) . "\n";
        foreach (array_slice($groups[$k], 0, 10) as $r) {
            $late = $r['due_date'] && $r['due_date'] < date('Y-m-d');
            $out .= '• ' . $r['party_name'] . ': ' . wa_money($store, $r['bal']) . ($r['due_date'] ? ' (due ' . $r['due_date'] . ($late ? ' ⚠️ overdue' : '') . ')' : '') . "\n";
        }
        $out .= "\n";
    }
    return rtrim($out);
}

// ───────── Execute a confirmed plan ─────────

function wa_execute($store, $p)
{
    $sid = (int) $store['id'];
    switch ($p['type']) {
        case 'sale':
            $sale = svc_sale_create($sid, $p['items'], $p['mode'], $p['customer'], null, null, $p['amount_paid']);
            $out = '✅ *Sale recorded* — ' . wa_money($store, $sale['total_amount']) . ' (profit ' . wa_money($store, $sale['profit']) . ')';
            $owe = $sale['total_amount'] - $sale['amount_paid'];
            if ($owe > 0.009) {
                $out .= "\n📝 " . $sale['customer_name'] . ' owes ' . wa_money($store, $owe) . ' (saved under debts).';
            }
            $low = [];
            foreach ($sale['items'] as $it) {
                $left = (int) val('SELECT quantity FROM products WHERE id = ?', [$it['product_id']]);
                $min = (int) val('SELECT reorder_level FROM products WHERE id = ?', [$it['product_id']]);
                if ($left <= $min) {
                    $low[] = $it['name'] . ' (' . $left . ' left)';
                }
            }
            return $out . ($low ? "\n⚠️ Running low: " . implode(', ', $low) : '');

        case 'stock':
            $names = [];
            foreach ($p['items'] as $it) {
                $row = svc_stock_adjust($sid, $it['product_id'], $it['change'], 'Via WhatsApp', $it['cost']);
                $names[] = $row['name'] . ' → ' . $row['quantity'];
            }
            return '✅ *Stock updated*' . "\n• " . implode("\n• ", $names);

        case 'new_product':
            $row = svc_product_add($sid, $p['name'], null, $p['cost'], $p['price'], $p['qty'], 5);
            return '✅ *Added* ' . $row['name'] . ' (' . $row['quantity'] . ' in stock).';

        case 'set_price':
            $row = row('SELECT * FROM products WHERE id = ? AND store_id = ? AND is_active = 1', [$p['product_id'], $sid]);
            if (!$row) {
                abort(404, 'Product not found');
            }
            q('UPDATE products SET cost_price = ?, sale_price = ? WHERE id = ?', [$p['cost'] !== null ? $p['cost'] : $row['cost_price'], $p['price'] !== null ? $p['price'] : $row['sale_price'], $row['id']]);
            return '✅ *Price updated* for ' . $row['name'] . '.';

        case 'expense':
            svc_expense_add($sid, $p['category'], $p['amount'], $p['note'], null, 'cash');
            return '✅ *Expense saved* — ' . $p['category'] . ' ' . wa_money($store, $p['amount']);

        case 'debt_add':
            svc_debt_add($sid, $p['direction'], $p['party'], null, $p['amount'], $p['due'], $p['note']);
            return '✅ *Debt recorded* — ' . ($p['direction'] === 'owed_to_me' ? $p['party'] . ' owes you ' : 'you owe ' . $p['party'] . ' ') . wa_money($store, $p['amount']);

        case 'debt_pay':
            $total = 0;
            foreach ($p['alloc'] as $a) {
                svc_debt_pay($sid, $a[0], $a[1], $p['mode']);
                $total += $a[1];
            }
            $left = (float) val("SELECT COALESCE(SUM(amount - amount_paid),0) FROM debts WHERE store_id = ? AND party_name = ? AND status <> 'settled'", [$sid, $p['party']]);
            return '✅ *Payment recorded* — ' . wa_money($store, $total) . ($left > 0.009 ? ' · balance ' . wa_money($store, $left) : ' · fully settled 🎉');

        case 'scan_add':
            $r = svc_stock_from_ai($sid, $p['items']);
            return '✅ *Stock added* — ' . $r['created'] . ' new, ' . $r['restocked'] . " restocked.\n"
                . ($r['created'] ? 'New products have no prices yet. Reply e.g. _set price Indomie cost 100 sell 150_.' : '');
    }
    return 'Sorry, something went wrong.';
}

// ───────── Pending confirmation state ─────────

function wa_set_pending($number, $payload)
{
    q('INSERT INTO whatsapp_pending (wa_number, payload, expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
        [$number, json_encode($payload), date('Y-m-d H:i:s', time() + 600)]);
}

function wa_take_pending($number)
{
    $r = row('SELECT payload, expires_at FROM whatsapp_pending WHERE wa_number = ?', [$number]);
    q('DELETE FROM whatsapp_pending WHERE wa_number = ?', [$number]);
    return $r && $r['expires_at'] >= date('Y-m-d H:i:s') ? json_decode($r['payload'], true) : null;
}

// ───────── Message handling ─────────

function wa_link_number($from, $code)
{
    $c = row('SELECT * FROM whatsapp_link_codes WHERE code = ? AND expires_at >= ?', [$code, date('Y-m-d H:i:s')]);
    if (!$c) {
        return '❌ That code is invalid or has expired. Open *WhatsApp* in the Store-AI app and create a new one.';
    }
    q('INSERT INTO whatsapp_links (user_id, store_id, wa_number) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), store_id = VALUES(store_id), chat_session_id = NULL',
        [$c['user_id'], $c['store_id'], $from]);
    q('DELETE FROM whatsapp_link_codes WHERE code = ?', [$code]);
    $store = row('SELECT name FROM stores WHERE id = ?', [$c['store_id']]);
    return '✅ Connected to *' . $store['name'] . "*!\n\n" . wa_help();
}

function wa_handle_text($link, $store, $text)
{
    $from = $link['wa_number'];
    $sid = (int) $store['id'];
    $t = strtolower(trim($text));
    $t = trim($t, " .!");

    if (in_array($t, ['yes', 'y', 'ok', 'okay', 'confirm', 'yep', 'yeah', 'sure', '1'], true)) {
        $p = wa_take_pending($from);
        return $p ? wa_execute($store, $p) : 'There’s nothing waiting for confirmation.';
    }
    if (in_array($t, ['no', 'n', 'cancel', 'nope', 'stop', '0'], true)) {
        return wa_take_pending($from) ? '👍 Cancelled. Nothing was saved.' : 'There’s nothing to cancel.';
    }
    if (in_array($t, ['help', 'menu', 'hi', 'hello', 'hey', 'start', '?'], true)) {
        return wa_help();
    }
    // a new request replaces anything unconfirmed
    q('DELETE FROM whatsapp_pending WHERE wa_number = ?', [$from]);

    // shortcuts that need no AI
    if ($t === 'today') {
        return wa_answer_report($store, ['period' => 'today']);
    }
    if ($t === 'stock') {
        return wa_answer_stock($store, [], []);
    }
    if ($t === 'debts' || $t === 'debt') {
        return wa_answer_debts($store);
    }

    $products = rows('SELECT id, name, quantity, cost_price, sale_price, reorder_level FROM products WHERE store_id = ? AND is_active = 1 ORDER BY name', [$sid]);
    $debts = rows("SELECT party_name FROM debts WHERE store_id = ? AND status <> 'settled' LIMIT 60", [$sid]);
    $d = wa_interpret($store, $text, $products, $debts);

    switch ($d['intent']) {
        case 'sale':        list($reply, $pending) = wa_plan_sale($store, $d, $products); break;
        case 'restock':     list($reply, $pending) = wa_plan_stock($store, $d, $products, false); break;
        case 'stock_remove': list($reply, $pending) = wa_plan_stock($store, $d, $products, true); break;
        case 'new_product': list($reply, $pending) = wa_plan_new_product($store, $d, $products); break;
        case 'set_price':   list($reply, $pending) = wa_plan_set_price($store, $d, $products); break;
        case 'expense':     list($reply, $pending) = wa_plan_expense($store, $d); break;
        case 'debt_add':    list($reply, $pending) = wa_plan_debt_add($store, $d); break;
        case 'debt_pay':    list($reply, $pending) = wa_plan_debt_pay($store, $d, $sid); break;
        case 'stock_check': return wa_answer_stock($store, $d, $products);
        case 'report':      return wa_answer_report($store, $d);
        case 'debts_list':  return wa_answer_debts($store);
        case 'help':        return wa_help();
        case 'question':
            list($sessionId, $answer) = chat_answer($store, (int) $link['chat_session_id'], mb_substr($text, 0, 2000));
            if ((int) $link['chat_session_id'] !== $sessionId) {
                q('UPDATE whatsapp_links SET chat_session_id = ? WHERE id = ?', [$sessionId, $link['link_id']]);
            }
            return wa_markdown($answer);
        default:
            return "I’m not sure what you mean. 🤔\n\n" . wa_help();
    }
    if ($pending) {
        wa_set_pending($from, $pending);
    }
    return $reply;
}

function wa_handle_image($link, $store, $mediaId)
{
    $bytes = wa_download_media($mediaId);
    $tmp = tempnam(sys_get_temp_dir(), 'wa');
    file_put_contents($tmp, $bytes);
    try {
        $scan = ai_scan_image($store, $tmp, 'photo', false);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
    if (!$scan['items']) {
        return '📷 I couldn’t spot any products in that photo. Try a closer, brighter shot, or type what you got, e.g. _received 20 peak milk_.';
    }
    $items = [];
    $out = "📷 *I found these:*\n";
    foreach ($scan['items'] as $it) {
        $items[] = ['id' => $it['id'], 'name' => $it['name'], 'quantity' => $it['quantity']];
        $out .= '• ' . $it['quantity'] . ' × ' . $it['name'] . ($it['match'] ? ' (in stock: ' . (int) $it['match']['quantity'] . ')' : ' (new)') . "\n";
    }
    wa_set_pending($link['wa_number'], ['type' => 'scan_add', 'items' => $items]);
    return rtrim($out) . "\n\nAdd these to your stock?" . wa_confirm_footer() . "\nWrong count? Reply *NO* and tell me the right numbers.";
}

function wa_handle_message($m)
{
    $wamid = isset($m['id']) ? (string) $m['id'] : '';
    $from = wa_norm_number(isset($m['from']) ? $m['from'] : '');
    if ($wamid === '' || $from === '') {
        return;
    }
    // WhatsApp retries; process each message once
    if (!q('INSERT IGNORE INTO whatsapp_inbox (wamid, wa_number, created_at) VALUES (?,?,?)', [$wamid, $from, date('Y-m-d H:i:s')])->rowCount()) {
        return;
    }
    if ((int) val('SELECT COUNT(*) FROM whatsapp_inbox WHERE wa_number = ? AND created_at >= ?', [$from, date('Y-m-d H:i:s', time() - 60)]) > (int) cfg('wa_rate_per_min', 40)) {
        return;
    }
    if (mt_rand(1, 50) === 1) {
        q("DELETE FROM whatsapp_inbox WHERE created_at < ? AND wamid NOT LIKE '_last_%'", [date('Y-m-d H:i:s', time() - 3 * 86400)]);
    }

    $type = isset($m['type']) ? $m['type'] : '';
    $text = $type === 'text' && isset($m['text']['body']) ? trim($m['text']['body']) : '';

    try {
        if ($text !== '' && preg_match('/^\s*link\s+(\d{6})\s*$/i', $text, $mm)) {
            wa_send($from, wa_link_number($from, $mm[1]));
            return;
        }

        $link = row('SELECT l.id AS link_id, l.wa_number, l.chat_session_id, l.user_id, s.*, t.name AS type_name
                     FROM whatsapp_links l JOIN stores s ON s.id = l.store_id JOIN store_types t ON t.id = s.store_type_id
                     WHERE l.wa_number = ?', [$from]);
        if (!$link) {
            wa_send($from, "👋 Hi! This number isn’t connected to a Store-AI account yet.\n\n1. Open the Store-AI app → *WhatsApp*\n2. Tap *Connect* to get a 6-digit code\n3. Send it here as: *LINK 123456*");
            return;
        }
        $store = $link; // joined store columns (id, name, currency, type_name…)

        if ($type === 'text' && $text !== '') {
            wa_send($from, wa_handle_text($link, $store, $text));
        } elseif ($type === 'image' && !empty($m['image']['id'])) {
            wa_send($from, '📷 Reading your photo…');
            wa_send($from, wa_handle_image($link, $store, $m['image']['id']));
        } else {
            wa_send($from, 'I can read text messages and photos of your stock. Send *HELP* to see what I can do.');
        }
    } catch (HttpError $e) {
        $msg = $e->status === 503 ? 'AI isn’t set up yet, but you can still use *TODAY*, *STOCK*, *DEBTS* and *HELP*.' : '❌ ' . $e->getMessage();
        wa_send($from, $msg);
    } catch (Throwable $e) {
        error_log('[store-ai wa] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        wa_send($from, '❌ Something went wrong on my side. Please try again.');
    }
}

// ───────── HTTP endpoints ─────────

function wa_webhook_verify($c)
{
    $token = (string) cfg('wa_verify_token');
    if ($token !== '' && qs('hub_mode') === 'subscribe' && hash_equals($token, (string) qs('hub_verify_token'))) {
        header('Content-Type: text/plain');
        echo (string) qs('hub_challenge');
        exit;
    }
    abort(403, 'Verification failed');
}

function wa_webhook_receive($c)
{
    $raw = file_get_contents('php://input');
    q('REPLACE INTO whatsapp_inbox (wamid, wa_number, created_at) VALUES (?,?,?)', ['_last_attempt', '-', date('Y-m-d H:i:s')]);
    $secret = (string) cfg('wa_app_secret');
    if ($secret === '') {
        abort(503, 'WhatsApp is not configured (wa_app_secret missing)');
    }
    $sig = header_in('X-Hub-Signature-256');
    if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
        error_log('[store-ai wa] webhook rejected: signature mismatch (is wa_app_secret the App secret from App settings > Basic?)');
        abort(403, 'Bad signature');
    }
    $payload = json_decode($raw, true);

    // Acknowledge right away so WhatsApp doesn't retry while we think.
    $body = '{"ok":true}';
    http_response_code(200);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
    ignore_user_abort(true);
    set_time_limit(120);

    q('REPLACE INTO whatsapp_inbox (wamid, wa_number, created_at) VALUES (?,?,?)', ['_last_webhook', '-', date('Y-m-d H:i:s')]);
    if (is_array($payload)) {
        foreach (isset($payload['entry']) ? (array) $payload['entry'] : [] as $e) {
            foreach (isset($e['changes']) ? (array) $e['changes'] : [] as $ch) {
                foreach (isset($ch['value']['messages']) ? (array) $ch['value']['messages'] : [] as $m) {
                    try {
                        wa_handle_message($m);
                    } catch (Throwable $ex) {
                        error_log('[store-ai wa] ' . $ex->getMessage());
                    }
                }
            }
        }
    }
    exit;
}

function wa_link_code($c)
{
    q('DELETE FROM whatsapp_link_codes WHERE user_id = ? OR expires_at < ?', [$c['user']['id'], date('Y-m-d H:i:s')]);
    for ($i = 0; $i < 5; $i++) {
        $code = sprintf('%06d', random_int(0, 999999));
        if (!val('SELECT code FROM whatsapp_link_codes WHERE code = ?', [$code])) {
            break;
        }
    }
    q('INSERT INTO whatsapp_link_codes (code, user_id, store_id, expires_at) VALUES (?,?,?,?)',
        [$code, $c['user']['id'], $c['store']['id'], date('Y-m-d H:i:s', time() + 900)]);
    $biz = wa_norm_number(cfg('wa_business_number'));
    respond(['code' => $code, 'expires_minutes' => 15, 'business_number' => $biz ?: null,
             'url' => $biz ? 'https://wa.me/' . $biz . '?text=' . rawurlencode('LINK ' . $code) : null]);
}

function wa_status($c)
{
    $biz = wa_norm_number(cfg('wa_business_number'));
    respond([
        'configured'      => wa_ready() && cfg('wa_app_secret') && cfg('wa_verify_token'),
        'business_number' => $biz ?: null,
        'last_webhook_at' => val("SELECT created_at FROM whatsapp_inbox WHERE wamid = '_last_webhook'"),
        'last_attempt_at' => val("SELECT created_at FROM whatsapp_inbox WHERE wamid = '_last_attempt'"),
        'links'           => rows('SELECT l.id, l.wa_number, l.created_at, u.name FROM whatsapp_links l JOIN users u ON u.id = l.user_id WHERE l.store_id = ? ORDER BY l.id', [$c['store']['id']]),
    ]);
}

function wa_unlink($c)
{
    $n = q('DELETE FROM whatsapp_links WHERE id = ? AND store_id = ?', [(int) $c['params']['id'], $c['store']['id']])->rowCount();
    if (!$n) {
        abort(404, 'Link not found');
    }
    respond(['ok' => true]);
}

route('GET', '/whatsapp/webhook', 'wa_webhook_verify', false);
route('POST', '/whatsapp/webhook', 'wa_webhook_receive', false);
route('POST', '/whatsapp/link-code', 'wa_link_code');
route('GET', '/whatsapp/status', 'wa_status');
route('DELETE', '/whatsapp/links/{id}', 'wa_unlink');
