<?php
// Sales (multi-item), debts (both directions), expenses.

const PAY_MODES = ['cash', 'card', 'transfer', 'credit'];

function debt_status($amount, $paid)
{
    return $paid >= $amount ? 'settled' : ($paid > 0 ? 'partial' : 'open');
}

function load_sale_items($saleIds)
{
    if (!$saleIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($saleIds), '?'));
    $by = [];
    foreach (rows("SELECT si.sale_id, si.product_id, p.name, si.quantity, si.unit_price, si.unit_cost, si.line_total, si.line_profit
                   FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id IN ($in) ORDER BY si.id", $saleIds) as $r) {
        $by[$r['sale_id']][] = $r;
    }
    return $by;
}

function sales_create($c)
{
    $sid = $c['store']['id'];
    $in = input();
    $items = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
    if (!$items) {
        abort(422, 'Add at least one item to the sale');
    }
    $mode = isset($in['payment_mode']) ? $in['payment_mode'] : 'cash';
    if (!in_array($mode, PAY_MODES, true)) {
        abort(422, 'Choose a payment mode: cash, card, transfer or credit');
    }
    $customer = str_in('customer_name', 150) ?: null;
    $soldAt = date('Y-m-d H:i:s');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lines = [];
        $left = [];   // remaining stock per product while validating
        $total = 0.0;
        $cost = 0.0;
        foreach ($items as $it) {
            $pid = (int) (isset($it['product_id']) ? $it['product_id'] : 0);
            $qty = (int) (isset($it['quantity']) ? $it['quantity'] : 0);
            if ($pid <= 0 || $qty <= 0) {
                abort(422, 'Each item needs a product and a quantity of at least 1');
            }
            $p = row('SELECT id, name, cost_price, sale_price, quantity FROM products WHERE id = ? AND store_id = ? AND is_active = 1 FOR UPDATE', [$pid, $sid]);
            if (!$p) {
                abort(404, 'A product in this sale no longer exists');
            }
            if (!isset($left[$pid])) {
                $left[$pid] = (int) $p['quantity'];
            }
            if ($qty > $left[$pid]) {
                abort(422, 'Not enough stock for ' . $p['name'] . ' (only ' . $left[$pid] . ' left)');
            }
            $left[$pid] -= $qty;
            $price = money_in('unit_price', $p['sale_price'], $it);
            $lines[] = [$pid, $qty, (float) $p['cost_price'], $price];
            $total += $qty * $price;
            $cost += $qty * (float) $p['cost_price'];
        }
        $total = round($total, 2);
        $cost = round($cost, 2);

        $paid = $mode === 'credit' ? money_in('amount_paid', 0) : money_in('amount_paid', $total);
        $paid = min($paid, $total);
        $balance = round($total - $paid, 2);
        if ($balance > 0 && !$customer) {
            abort(422, 'Enter the customer name when a sale is not fully paid');
        }

        $saleId = insert('INSERT INTO sales (store_id, customer_name, total_amount, total_cost, profit, amount_paid, payment_mode, sold_at) VALUES (?,?,?,?,?,?,?,?)',
            [$sid, $customer, $total, $cost, round($total - $cost, 2), $paid, $mode, $soldAt]);

        foreach ($lines as $l) {
            q('INSERT INTO sale_items (sale_id, product_id, quantity, unit_cost, unit_price) VALUES (?,?,?,?,?)', [$saleId, $l[0], $l[1], $l[2], $l[3]]);
            q('UPDATE products SET quantity = quantity - ? WHERE id = ?', [$l[1], $l[0]]);
            log_stock($sid, $l[0], -$l[1], 'sale', $saleId);
        }

        if ($balance > 0) {
            $due = valid_date(isset($in['due_date']) ? $in['due_date'] : null, null);
            insert('INSERT INTO debts (store_id, direction, party_name, party_phone, amount, amount_paid, due_date, sale_id, note, status) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$sid, 'owed_to_me', $customer, str_in('customer_phone', 30) ?: null, $balance, 0, $due, $saleId, 'Unpaid balance on sale #' . $saleId, 'open']);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    $sale = row('SELECT * FROM sales WHERE id = ?', [$saleId]);
    $sale['items'] = load_sale_items([$saleId])[$saleId];
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
    $dir = isset(input()['direction']) ? input()['direction'] : '';
    if (!in_array($dir, ['owed_to_me', 'i_owe'], true)) {
        abort(422, 'Choose whether someone owes you or you owe someone');
    }
    $amount = money_in('amount');
    if ($amount <= 0) {
        abort(422, 'Amount must be greater than zero');
    }
    $id = insert('INSERT INTO debts (store_id, direction, party_name, party_phone, amount, due_date, note) VALUES (?,?,?,?,?,?,?)',
        [$c['store']['id'], $dir, str_in('party_name', 150, true), str_in('party_phone', 30) ?: null, $amount,
         valid_date(isset(input()['due_date']) ? input()['due_date'] : null, null), str_in('note', 255) ?: null]);
    respond(['debt' => row('SELECT * FROM debts WHERE id = ?', [$id])], 201);
}

function debts_pay($c)
{
    $sid = $c['store']['id'];
    $d = debt_or_404($sid, $c['params']['id']);
    $amt = money_in('amount');
    $remaining = round($d['amount'] - $d['amount_paid'], 2);
    if ($amt <= 0) {
        abort(422, 'Enter an amount greater than zero');
    }
    if ($amt > $remaining) {
        abort(422, 'That is more than the outstanding balance of ' . number_format($remaining, 2));
    }
    $mode = isset(input()['payment_mode']) ? input()['payment_mode'] : 'cash';
    if (!in_array($mode, ['cash', 'card', 'transfer'], true)) {
        abort(422, 'Choose cash, card or transfer');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        insert('INSERT INTO debt_payments (debt_id, amount, payment_mode, paid_at) VALUES (?,?,?,?)', [$d['id'], $amt, $mode, date('Y-m-d H:i:s')]);
        $paid = round($d['amount_paid'] + $amt, 2);
        q('UPDATE debts SET amount_paid = ?, status = ? WHERE id = ?', [$paid, debt_status($d['amount'], $paid), $d['id']]);
        // A customer settling a credit sale also counts as collected money on that sale.
        if ($d['direction'] === 'owed_to_me' && $d['sale_id']) {
            q('UPDATE sales SET amount_paid = LEAST(total_amount, amount_paid + ?) WHERE id = ?', [$amt, $d['sale_id']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    respond(['debt' => row('SELECT * FROM debts WHERE id = ?', [$d['id']])]);
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
    $amount = money_in('amount');
    if ($amount <= 0) {
        abort(422, 'Amount must be greater than zero');
    }
    $mode = isset(input()['payment_mode']) ? input()['payment_mode'] : 'cash';
    $id = insert('INSERT INTO expenses (store_id, category, amount, note, spent_at, payment_mode) VALUES (?,?,?,?,?,?)',
        [$c['store']['id'], str_in('category', 80, true), $amount, str_in('note', 255) ?: null,
         valid_date(isset(input()['spent_at']) ? input()['spent_at'] : null, date('Y-m-d')), in_array($mode, ['cash', 'card', 'transfer'], true) ? $mode : 'cash']);
    respond(['expense' => row('SELECT * FROM expenses WHERE id = ?', [$id])], 201);
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
