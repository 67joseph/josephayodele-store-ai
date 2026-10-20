<?php
// Reports, dashboard, and the AI chat that reasons over them.

function report_data($sid, $from, $to, $detail = true)
{
    $a = $from . ' 00:00:00';
    $b = $to . ' 23:59:59';

    $s = row('SELECT COUNT(*) AS sales_count, COALESCE(SUM(total_amount),0) AS revenue, COALESCE(SUM(total_cost),0) AS cogs,
                     COALESCE(SUM(profit),0) AS gross_profit, COALESCE(SUM(amount_paid),0) AS collected
              FROM sales WHERE store_id = ? AND sold_at BETWEEN ? AND ?', [$sid, $a, $b]);
    $items = (int) val('SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                        WHERE s.store_id = ? AND s.sold_at BETWEEN ? AND ?', [$sid, $a, $b]);
    $exp = (float) val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE store_id = ? AND spent_at BETWEEN ? AND ?', [$sid, $from, $to]);

    $out = [
        'from'         => $from,
        'to'           => $to,
        'sales_count'  => (int) $s['sales_count'],
        'items_sold'   => $items,
        'revenue'      => (float) $s['revenue'],
        'cogs'         => (float) $s['cogs'],
        'gross_profit' => (float) $s['gross_profit'],
        'expenses'     => $exp,
        'net_profit'   => round((float) $s['gross_profit'] - $exp, 2),
        'collected'    => (float) $s['collected'],
        'avg_sale'     => $s['sales_count'] ? round($s['revenue'] / $s['sales_count'], 2) : 0,
    ];
    if (!$detail) {
        return $out;
    }

    $out['by_day'] = rows('SELECT DATE(sold_at) AS day, COUNT(*) AS sales, SUM(total_amount) AS revenue, SUM(profit) AS profit
                           FROM sales WHERE store_id = ? AND sold_at BETWEEN ? AND ? GROUP BY DATE(sold_at) ORDER BY day', [$sid, $a, $b]);
    $out['by_payment'] = rows('SELECT payment_mode AS mode, COUNT(*) AS sales, SUM(total_amount) AS total, SUM(amount_paid) AS paid
                               FROM sales WHERE store_id = ? AND sold_at BETWEEN ? AND ? GROUP BY payment_mode ORDER BY total DESC', [$sid, $a, $b]);
    $out['top_products'] = rows('SELECT p.id, p.name, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue, SUM(si.line_profit) AS profit
                                 FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
                                 WHERE s.store_id = ? AND s.sold_at BETWEEN ? AND ? GROUP BY p.id, p.name ORDER BY revenue DESC LIMIT 10', [$sid, $a, $b]);
    $out['expenses_by_category'] = rows('SELECT category, SUM(amount) AS total FROM expenses WHERE store_id = ? AND spent_at BETWEEN ? AND ?
                                         GROUP BY category ORDER BY total DESC', [$sid, $from, $to]);
    return $out;
}

function snapshot_data($sid)
{
    $inv = row('SELECT COUNT(*) AS products, COALESCE(SUM(quantity),0) AS units, COALESCE(SUM(quantity*cost_price),0) AS cost_value,
                       COALESCE(SUM(quantity*sale_price),0) AS retail_value
                FROM products WHERE store_id = ? AND is_active = 1', [$sid]);
    $low = rows('SELECT id, name, quantity, reorder_level FROM products WHERE store_id = ? AND is_active = 1 AND quantity <= reorder_level
                 ORDER BY quantity, name LIMIT 20', [$sid]);
    $debts = ['owed_to_me' => 0, 'i_owe' => 0, 'overdue_count' => 0];
    foreach (rows("SELECT direction, SUM(amount - amount_paid) AS o FROM debts WHERE store_id = ? AND status <> 'settled' GROUP BY direction", [$sid]) as $r) {
        $debts[$r['direction']] = (float) $r['o'];
    }
    $debts['overdue_count'] = (int) val("SELECT COUNT(*) FROM debts WHERE store_id = ? AND status <> 'settled' AND due_date IS NOT NULL AND due_date < CURDATE()", [$sid]);
    return [
        'inventory' => ['products' => (int) $inv['products'], 'units' => (int) $inv['units'],
                        'cost_value' => (float) $inv['cost_value'], 'retail_value' => (float) $inv['retail_value']],
        'low_stock' => $low,
        'debts'     => $debts,
    ];
}

function reports_get($c)
{
    $sid = $c['store']['id'];
    $to = valid_date(qs('to'), date('Y-m-d'));
    $from = valid_date(qs('from'), date('Y-m-d', strtotime('-29 days', strtotime($to))));
    if ($from > $to) {
        abort(422, 'The start date must be before the end date');
    }
    respond(['report' => report_data($sid, $from, $to), 'snapshot' => snapshot_data($sid)]);
}

function dashboard_get($c)
{
    $sid = $c['store']['id'];
    $today = date('Y-m-d');
    $sales = rows('SELECT * FROM sales WHERE store_id = ? ORDER BY sold_at DESC, id DESC LIMIT 5', [$sid]);
    $items = load_sale_items(array_column($sales, 'id'));
    foreach ($sales as &$s) {
        $s['items'] = isset($items[$s['id']]) ? $items[$s['id']] : [];
    }
    respond([
        'today'    => report_data($sid, $today, $today, false),
        'month'    => report_data($sid, date('Y-m-01'), $today, false),
        'week'     => rows(
            'SELECT DATE(sold_at) AS day, SUM(total_amount) AS revenue, SUM(profit) AS profit FROM sales
             WHERE store_id = ? AND sold_at >= ? GROUP BY DATE(sold_at) ORDER BY day',
            [$sid, date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']),
        'snapshot' => snapshot_data($sid),
        'recent'   => $sales,
    ]);
}

// ───────── AI chat ─────────

function chat_context($store)
{
    $sid = $store['id'];
    $today = date('Y-m-d');
    $ctx = [
        'store'         => ['name' => $store['name'], 'type' => $store['type_name'], 'currency' => $store['currency'], 'today' => $today],
        'today'         => report_data($sid, $today, $today, false),
        'last_7_days'   => report_data($sid, date('Y-m-d', strtotime('-6 days')), $today),
        'last_30_days'  => report_data($sid, date('Y-m-d', strtotime('-29 days')), $today),
        'this_month'    => report_data($sid, date('Y-m-01'), $today, false),
        'last_month'    => report_data($sid, date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month')), false),
        'snapshot'      => snapshot_data($sid),
        'products'      => rows('SELECT name, quantity, cost_price, sale_price, reorder_level FROM products WHERE store_id = ? AND is_active = 1 ORDER BY name LIMIT 150', [$sid]),
        'open_debts'    => rows("SELECT direction, party_name, amount - amount_paid AS outstanding, due_date FROM debts
                                 WHERE store_id = ? AND status <> 'settled' ORDER BY due_date IS NULL, due_date LIMIT 40", [$sid]),
    ];
    return json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function chat_send($c)
{
    $store = $c['store'];
    $msg = str_in('message', 2000, true);
    $sessionId = (int) (input()['session_id'] ?? 0);
    if ($sessionId) {
        if (!val('SELECT id FROM chat_sessions WHERE id = ? AND store_id = ?', [$sessionId, $store['id']])) {
            abort(404, 'Conversation not found');
        }
    } else {
        $sessionId = insert('INSERT INTO chat_sessions (store_id, title) VALUES (?,?)', [$store['id'], mb_substr($msg, 0, 60)]);
    }

    $history = array_reverse(rows('SELECT role, content FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 12', [$sessionId]));
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $msg];

    $system = "You are the business analyst inside a small-store sales and inventory app. The owner chats with you about their shop.\n"
        . "Answer ONLY from the JSON data below (live figures from their store, in {$store['currency']}). Never invent numbers; if the data can't answer, say so and say what to record to find out.\n"
        . "Be concise and practical: lead with the answer, then 2-4 short bullet points with specific figures, then one clear suggested action when useful. "
        . "Use plain text with simple '-' bullets and **bold** for key figures. No tables, no headings. Format money with thousand separators.\n"
        . "Profit notes: gross_profit = revenue - cogs; net_profit = gross_profit - expenses. 'owed_to_me' = customers owe the shop; 'i_owe' = the shop owes suppliers.\n\n"
        . "STORE DATA:\n" . chat_context($store);

    $reply = trim(ai_chat($system, $messages, 1500));
    if ($reply === '') {
        $reply = "I couldn't come up with an answer. Please try rephrasing.";
    }
    insert('INSERT INTO chat_messages (session_id, role, content) VALUES (?,?,?)', [$sessionId, 'user', $msg]);
    insert('INSERT INTO chat_messages (session_id, role, content) VALUES (?,?,?)', [$sessionId, 'assistant', $reply]);
    respond(['session_id' => $sessionId, 'reply' => $reply]);
}

function chat_sessions($c)
{
    respond(['sessions' => rows('SELECT id, title, created_at FROM chat_sessions WHERE store_id = ? ORDER BY id DESC LIMIT 30', [$c['store']['id']])]);
}

function chat_session_get($c)
{
    $s = row('SELECT id, title FROM chat_sessions WHERE id = ? AND store_id = ?', [$c['params']['id'], $c['store']['id']]);
    if (!$s) {
        abort(404, 'Conversation not found');
    }
    respond(['session' => $s, 'messages' => rows('SELECT role, content, created_at FROM chat_messages WHERE session_id = ? ORDER BY id', [$s['id']])]);
}

function chat_session_delete($c)
{
    q('DELETE FROM chat_sessions WHERE id = ? AND store_id = ?', [$c['params']['id'], $c['store']['id']]);
    respond(['ok' => true]);
}

route('GET', '/dashboard', 'dashboard_get');
route('GET', '/reports', 'reports_get');
route('POST', '/chat', 'chat_send');
route('GET', '/chat/sessions', 'chat_sessions');
route('GET', '/chat/sessions/{id}', 'chat_session_get');
route('DELETE', '/chat/sessions/{id}', 'chat_session_delete');
