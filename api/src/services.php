<?php
// Business operations shared by the web API and the WhatsApp bot.
// Every function takes an explicit store id, validates, runs in a transaction, and throws HttpError on bad input.

const PAY_MODES = ['cash', 'card', 'transfer', 'credit'];

function svc_run_tx($fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function debt_status($amount, $paid)
{
    return $paid >= $amount ? 'settled' : ($paid > 0 ? 'partial' : 'open');
}

function log_stock($storeId, $productId, $change, $reason, $refId = null, $note = null)
{
    q('INSERT INTO stock_movements (store_id, product_id, change_qty, reason, ref_id, note) VALUES (?,?,?,?,?,?)',
        [$storeId, $productId, $change, $reason, $refId, $note]);
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

// ───────── Products & stock ─────────

function svc_product_add($sid, $name, $sku, $cost, $price, $qty, $reorder)
{
    $name = trim($name);
    if ($name === '') {
        abort(422, 'Product name is required');
    }
    $sku = $sku ?: null;
    $qty = max(0, (int) $qty);
    if ($sku && val('SELECT id FROM products WHERE store_id = ? AND sku = ?', [$sid, $sku])) {
        abort(409, 'Another product already uses this SKU');
    }
    $id = svc_run_tx(function () use ($sid, $name, $sku, $cost, $price, $qty, $reorder) {
        $id = insert('INSERT INTO products (store_id, name, sku, cost_price, sale_price, quantity, reorder_level) VALUES (?,?,?,?,?,?,?)',
            [$sid, $name, $sku, $cost, $price, $qty, $reorder]);
        if ($qty > 0) {
            log_stock($sid, $id, $qty, 'initial');
        }
        return $id;
    });
    return row('SELECT * FROM products WHERE id = ?', [$id]);
}

// Add (positive) or remove (negative) stock.
function svc_stock_adjust($sid, $productId, $change, $note = null, $newCost = null)
{
    $p = row('SELECT * FROM products WHERE id = ? AND store_id = ? AND is_active = 1', [$productId, $sid]);
    if (!$p) {
        abort(404, 'Product not found');
    }
    $change = (int) $change;
    if ($change === 0) {
        abort(422, 'Enter a quantity to add or remove');
    }
    svc_run_tx(function () use ($sid, $p, $change, $note, $newCost) {
        $ok = q('UPDATE products SET quantity = quantity + ? WHERE id = ? AND quantity + ? >= 0', [$change, $p['id'], $change])->rowCount();
        if (!$ok) {
            abort(422, 'Cannot remove more than the ' . $p['quantity'] . ' in stock');
        }
        if ($change > 0 && $newCost !== null && $newCost > 0) {
            q('UPDATE products SET cost_price = ? WHERE id = ?', [$newCost, $p['id']]);
        }
        log_stock($sid, $p['id'], $change, $change > 0 ? 'restock' : 'adjustment', null, $note);
    });
    return row('SELECT * FROM products WHERE id = ?', [$p['id']]);
}

// ───────── Sales ─────────

// $items: [{product_id, quantity, unit_price?}]. $amountPaid null = default (full, or 0 for credit).
function svc_sale_create($sid, $items, $mode, $customer, $phone = null, $due = null, $amountPaid = null)
{
    if (!is_array($items) || !$items) {
        abort(422, 'Add at least one item to the sale');
    }
    if (!in_array($mode, PAY_MODES, true)) {
        abort(422, 'Choose a payment mode: cash, card, transfer or credit');
    }
    $customer = $customer !== null && trim($customer) !== '' ? mb_substr(trim($customer), 0, 150) : null;
    $soldAt = date('Y-m-d H:i:s');

    $saleId = svc_run_tx(function () use ($sid, $items, $mode, $customer, $phone, $due, $amountPaid, $soldAt) {
        $lines = [];
        $left = [];
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

        $paid = $amountPaid === null ? ($mode === 'credit' ? 0.0 : $total) : (float) $amountPaid;
        $paid = max(0, min($paid, $total));
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
            insert('INSERT INTO debts (store_id, direction, party_name, party_phone, amount, amount_paid, due_date, sale_id, note, status) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$sid, 'owed_to_me', $customer, $phone ?: null, $balance, 0, valid_date($due, null), $saleId, 'Unpaid balance on sale #' . $saleId, 'open']);
        }
        return $saleId;
    });

    $sale = row('SELECT * FROM sales WHERE id = ?', [$saleId]);
    $items = load_sale_items([$saleId]);
    $sale['items'] = $items[$saleId];
    return $sale;
}

// ───────── Debts ─────────

function svc_debt_add($sid, $direction, $party, $phone, $amount, $due, $note)
{
    if (!in_array($direction, ['owed_to_me', 'i_owe'], true)) {
        abort(422, 'Choose whether someone owes you or you owe someone');
    }
    if ($amount <= 0) {
        abort(422, 'Amount must be greater than zero');
    }
    $party = trim($party);
    if ($party === '') {
        abort(422, 'Party name is required');
    }
    $id = insert('INSERT INTO debts (store_id, direction, party_name, party_phone, amount, due_date, note) VALUES (?,?,?,?,?,?,?)',
        [$sid, $direction, mb_substr($party, 0, 150), $phone ?: null, $amount, valid_date($due, null), $note ?: null]);
    return row('SELECT * FROM debts WHERE id = ?', [$id]);
}

function svc_debt_pay($sid, $debtId, $amt, $mode)
{
    $d = row('SELECT * FROM debts WHERE id = ? AND store_id = ?', [$debtId, $sid]);
    if (!$d) {
        abort(404, 'Debt not found');
    }
    $remaining = round($d['amount'] - $d['amount_paid'], 2);
    if ($amt <= 0) {
        abort(422, 'Enter an amount greater than zero');
    }
    if ($amt > $remaining) {
        abort(422, 'That is more than the outstanding balance of ' . number_format($remaining, 2));
    }
    if (!in_array($mode, ['cash', 'card', 'transfer'], true)) {
        abort(422, 'Choose cash, card or transfer');
    }
    svc_run_tx(function () use ($d, $amt, $mode) {
        insert('INSERT INTO debt_payments (debt_id, amount, payment_mode, paid_at) VALUES (?,?,?,?)', [$d['id'], $amt, $mode, date('Y-m-d H:i:s')]);
        $paid = round($d['amount_paid'] + $amt, 2);
        q('UPDATE debts SET amount_paid = ?, status = ? WHERE id = ?', [$paid, debt_status($d['amount'], $paid), $d['id']]);
        // A customer settling a credit sale also counts as collected money on that sale.
        if ($d['direction'] === 'owed_to_me' && $d['sale_id']) {
            q('UPDATE sales SET amount_paid = LEAST(total_amount, amount_paid + ?) WHERE id = ?', [$amt, $d['sale_id']]);
        }
    });
    return row('SELECT * FROM debts WHERE id = ?', [$d['id']]);
}

// ───────── Expenses ─────────

function svc_expense_add($sid, $category, $amount, $note, $date, $mode)
{
    $category = trim($category);
    if ($category === '') {
        abort(422, 'Category is required');
    }
    if ($amount <= 0) {
        abort(422, 'Amount must be greater than zero');
    }
    $id = insert('INSERT INTO expenses (store_id, category, amount, note, spent_at, payment_mode) VALUES (?,?,?,?,?,?)',
        [$sid, mb_substr($category, 0, 80), $amount, $note ? mb_substr($note, 0, 255) : null, valid_date($date, date('Y-m-d')),
         in_array($mode, ['cash', 'card', 'transfer'], true) ? $mode : 'cash']);
    return row('SELECT * FROM expenses WHERE id = ?', [$id]);
}

// ───────── AI stock confirmation (shared by the web and WhatsApp) ─────────
// $items: [{id?, name, quantity, cost_price?, sale_price?}] → ['created' => n, 'restocked' => n]
function svc_stock_from_ai($sid, $items)
{
    if (!is_array($items) || !$items) {
        abort(422, 'Nothing to add');
    }
    $created = 0;
    $restocked = 0;
    $touched = [];

    svc_run_tx(function () use ($sid, $items, &$created, &$restocked, &$touched) {
        foreach ($items as $it) {
            $name = isset($it['name']) ? mb_substr(trim((string) $it['name']), 0, 190) : '';
            $qty = (int) (isset($it['quantity']) ? $it['quantity'] : 0);
            if ($name === '' || $qty < 1) {
                abort(422, 'Each item needs a name and a quantity of at least 1');
            }
            $cost = money_in('cost_price', 0, $it);
            $price = money_in('sale_price', 0, $it);

            $upload = null;
            if (!empty($it['id'])) {
                $upload = row('SELECT i.id AS item_id, u.id AS upload_id, u.image_path FROM ai_upload_items i
                               JOIN ai_uploads u ON u.id = i.upload_id WHERE i.id = ? AND u.store_id = ?', [(int) $it['id'], $sid]);
            }

            $existing = row('SELECT * FROM products WHERE store_id = ? AND is_active = 1 AND LOWER(name) = LOWER(?)', [$sid, $name]);
            if ($existing) {
                q('UPDATE products SET quantity = quantity + ?, cost_price = IF(? > 0, ?, cost_price), sale_price = IF(? > 0, ?, sale_price) WHERE id = ?',
                    [$qty, $cost, $cost, $price, $price, $existing['id']]);
                $pid = (int) $existing['id'];
                $restocked++;
            } else {
                $pid = insert('INSERT INTO products (store_id, name, cost_price, sale_price, quantity, reorder_level, image_path) VALUES (?,?,?,?,?,?,?)',
                    [$sid, $name, $cost, $price, $qty, 5, $upload ? $upload['image_path'] : null]);
                $created++;
            }
            log_stock($sid, $pid, $qty, 'ai_upload', $upload ? $upload['upload_id'] : null);

            if ($upload) {
                q('UPDATE ai_upload_items SET final_name = ?, final_qty = ?, cost_price = ?, sale_price = ?, product_id = ? WHERE id = ?',
                    [$name, $qty, $cost, $price, $pid, $upload['item_id']]);
                $touched[$upload['upload_id']] = true;
            }
        }
        foreach (array_keys($touched) as $uid) {
            q("UPDATE ai_uploads SET status = 'confirmed' WHERE id = ?", [$uid]);
        }
    });
    return ['created' => $created, 'restocked' => $restocked];
}
