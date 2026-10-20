<?php
// Sales (multi-item), debts (both directions), expenses.



function sales_create($c)
{
    $in = input();
    $paid = isset($in['amount_paid']) && $in['amount_paid'] !== '' ? money_in('amount_paid', 0) : null;
    $sale = svc_sale_create($c['store']['id'], isset($in['items']) ? $in['items'] : [], isset($in['payment_mode']) ? $in['payment_mode'] : 'cash',
        str_in('customer_name', 150), str_in('customer_phone', 30), isset($in['due_date']) ? $in['due_date'] : null, $paid);
    respond(['sale' => $sale], 201);
}

function sales_list($c)
{
    $sid = $c['store']['id'];
    $from = valid_date(qs('from'), date('Y-m-d', strtotime('-30 days')));
    $to = valid_date(qs('to'), date('Y-m-d'));
    $limit = min(100, max(1, (int) qs('limit', 50)));
    $offset = max(0, (int) qs('offset', 0));
    $sales = rows("SELECT * FROM sales WHERE store_id = ? AND sold_at BETWEEN ? AND ? ORDER BY sold_at DESC, id DESC LIMIT $limit OFFSET $offset",
        [$sid, $from . ' 00:00:00', $to . ' 23:59:59']);
    $items = load_sale_items(array_column($sales, 'id'));
    foreach ($sales as &$s) {
        $s['items'] = isset($items[$s['id']]) ? $items[$s['id']] : [];
    }
    respond(['sales' => $sales]);
}

// Void a sale: put the stock back and remove its debt.
function sales_delete($c)
{
    $sid = $c['store']['id'];
    $sale = row('SELECT * FROM sales WHERE id = ? AND store_id = ?', [$c['params']['id'], $sid]);
    if (!$sale) {
        abort(404, 'Sale not found');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (rows('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?', [$sale['id']]) as $it) {
            q('UPDATE products SET quantity = quantity + ? WHERE id = ?', [$it['quantity'], $it['product_id']]);
            log_stock($sid, $it['product_id'], (int) $it['quantity'], 'return', $sale['id'], 'Sale #' . $sale['id'] . ' voided');
        }
        q('DELETE FROM debts WHERE sale_id = ?', [$sale['id']]);
        q('DELETE FROM sales WHERE id = ?', [$sale['id']]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    respond(['ok' => true]);
}

// ───────── Debts ─────────

function debt_or_404($sid, $id)
{
    $d = row('SELECT * FROM debts WHERE id = ? AND store_id = ?', [$id, $sid]);
    if (!$d) {
        abort(404, 'Debt not found');
    }
    return $d;
}

function debts_list($c)
{
    $sid = $c['store']['id'];
    $sql = 'SELECT * FROM debts WHERE store_id = ?';
    $p = [$sid];
    if (in_array(qs('direction'), ['owed_to_me', 'i_owe'], true)) {
        $sql .= ' AND direction = ?';
        $p[] = qs('direction');
    }
    if (qs('status') === 'open') {
        $sql .= " AND status <> 'settled'";
    } elseif (qs('status') === 'settled') {
        $sql .= " AND status = 'settled'";
    }
    $list = rows($sql . ' ORDER BY (status = "settled"), due_date IS NULL, due_date, id DESC', $p);
    $sum = rows("SELECT direction, SUM(amount - amount_paid) AS outstanding FROM debts WHERE store_id = ? AND status <> 'settled' GROUP BY direction", [$sid]);
    $totals = ['owed_to_me' => 0, 'i_owe' => 0];
    foreach ($sum as $r) {
        $totals[$r['direction']] = (float) $r['outstanding'];
    }
    respond(['debts' => $list, 'totals' => $totals]);
}

function debts_get($c)
{
    $d = debt_or_404($c['store']['id'], $c['params']['id']);
    $d['payments'] = rows('SELECT * FROM debt_payments WHERE debt_id = ? ORDER BY paid_at DESC, id DESC', [$d['id']]);
    respond(['debt' => $d]);
}

function debts_create($c)
{
    $in = input();
    $d = svc_debt_add($c['store']['id'], isset($in['direction']) ? $in['direction'] : '', str_in('party_name', 150, true), str_in('party_phone', 30),
        money_in('amount'), isset($in['due_date']) ? $in['due_date'] : null, str_in('note', 255));
    respond(['debt' => $d], 201);
}

function debts_pay($c)
{
    $in = input();
    $d = svc_debt_pay($c['store']['id'], (int) $c['params']['id'], money_in('amount'), isset($in['payment_mode']) ? $in['payment_mode'] : 'cash');
    respond(['debt' => $d]);
}

function debts_delete($c)
{
    $d = debt_or_404($c['store']['id'], $c['params']['id']);
    q('DELETE FROM debts WHERE id = ?', [$d['id']]);
    respond(['ok' => true]);
}

// ───────── Expenses ─────────

function expenses_list($c)
{
    $sid = $c['store']['id'];
    $from = valid_date(qs('from'), date('Y-m-01'));
    $to = valid_date(qs('to'), date('Y-m-d'));
    $list = rows('SELECT * FROM expenses WHERE store_id = ? AND spent_at BETWEEN ? AND ? ORDER BY spent_at DESC, id DESC', [$sid, $from, $to]);
    $total = array_sum(array_map(function ($e) { return (float) $e['amount']; }, $list));
    respond(['expenses' => $list, 'total' => round($total, 2)]);
}

function expenses_create($c)
{
    $in = input();
    $e = svc_expense_add($c['store']['id'], str_in('category', 80, true), money_in('amount'), str_in('note', 255),
        isset($in['spent_at']) ? $in['spent_at'] : null, isset($in['payment_mode']) ? $in['payment_mode'] : 'cash');
    respond(['expense' => $e], 201);
}

function expenses_delete($c)
{
    $n = q('DELETE FROM expenses WHERE id = ? AND store_id = ?', [$c['params']['id'], $c['store']['id']])->rowCount();
    if (!$n) {
        abort(404, 'Expense not found');
    }
    respond(['ok' => true]);
}

route('POST', '/sales', 'sales_create');
route('GET', '/sales', 'sales_list');
route('DELETE', '/sales/{id}', 'sales_delete');
route('GET', '/debts', 'debts_list');
route('POST', '/debts', 'debts_create');
route('GET', '/debts/{id}', 'debts_get');
route('POST', '/debts/{id}/payments', 'debts_pay');
route('DELETE', '/debts/{id}', 'debts_delete');
route('GET', '/expenses', 'expenses_list');
route('POST', '/expenses', 'expenses_create');
route('DELETE', '/expenses/{id}', 'expenses_delete');
