<?php
// Products, stock movements, and AI photo scanning.

function product_or_404($storeId, $id)
{
    $p = row('SELECT * FROM products WHERE id = ? AND store_id = ? AND is_active = 1', [$id, $storeId]);
    if (!$p) {
        abort(404, 'Product not found');
    }
    return $p;
}


function products_list($c)
{
    $sql = 'SELECT * FROM products WHERE store_id = ? AND is_active = 1';
    $p = [$c['store']['id']];
    if ($s = qs('q')) {
        $sql .= ' AND (name LIKE ? OR sku LIKE ?)';
        $p[] = '%' . $s . '%';
        $p[] = '%' . $s . '%';
    }
    if (qs('low')) {
        $sql .= ' AND quantity <= reorder_level';
    }
    respond(['products' => rows($sql . ' ORDER BY name', $p)]);
}

function product_fields($existing = null)
{
    $in = input();
    $name = str_in('name', 190, true);
    $sku = str_in('sku', 64) ?: null;
    return [
        $name, $sku,
        money_in('cost_price', $existing ? $existing['cost_price'] : 0),
        money_in('sale_price', $existing ? $existing['sale_price'] : 0),
        max(0, (int) (isset($in['reorder_level']) ? $in['reorder_level'] : ($existing ? $existing['reorder_level'] : 0))),
    ];
}

function products_create($c)
{
    list($name, $sku, $cost, $price, $reorder) = product_fields();
    $qty = (int) (isset(input()['quantity']) ? input()['quantity'] : 0);
    respond(['product' => svc_product_add($c['store']['id'], $name, $sku, $cost, $price, $qty, $reorder)], 201);
}

function products_update($c)
{
    $sid = $c['store']['id'];
    $old = product_or_404($sid, $c['params']['id']);
    list($name, $sku, $cost, $price, $reorder) = product_fields($old);
    if ($sku && val('SELECT id FROM products WHERE store_id = ? AND sku = ? AND id <> ?', [$sid, $sku, $old['id']])) {
        abort(409, 'Another product already uses this SKU');
    }
    q('UPDATE products SET name=?, sku=?, cost_price=?, sale_price=?, reorder_level=? WHERE id=?',
        [$name, $sku, $cost, $price, $reorder, $old['id']]);
    respond(['product' => row('SELECT * FROM products WHERE id = ?', [$old['id']])]);
}

function products_delete($c)
{
    $p = product_or_404($c['store']['id'], $c['params']['id']);
    // Soft delete keeps past sales intact; free the SKU for reuse.
    q('UPDATE products SET is_active = 0, sku = NULL WHERE id = ?', [$p['id']]);
    respond(['ok' => true]);
}

// Add / remove stock. `change` may be negative (damage, correction).
function products_stock($c)
{
    $in = input();
    $cost = isset($in['cost_price']) && $in['cost_price'] !== '' ? money_in('cost_price') : null;
    $p = svc_stock_adjust($c['store']['id'], (int) $c['params']['id'], (int) (isset($in['change']) ? $in['change'] : 0), str_in('note', 255) ?: null, $cost);
    respond(['product' => $p]);
}

// ───────── AI photo scan ─────────

function normalize_files($key)
{
    $out = [];
    if (empty($_FILES[$key])) {
        return $out;
    }
    $f = $_FILES[$key];
    if (!is_array($f['name'])) {
        foreach ($f as $k => $v) {
            $f[$k] = [$v];
        }
    }
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'tmp' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function parse_ai_json($text)
{
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false) {
        return null;
    }
    $j = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

// Save one image, ask the AI what products are in it, store the suggestions. Used by the web and WhatsApp.
// $isUploaded: true for PHP uploads (move_uploaded_file), false for files we wrote ourselves (rename).
function ai_scan_image($store, $srcPath, $displayName, $isUploaded = true)
{
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $system = 'You help a small ' . $store['type_name'] . ' shop owner take inventory from photos. '
        . 'Identify each distinct product visible, using brand, variant and size when readable (e.g. "Indomie Chicken Noodles 70g"). '
        . 'Count the units of each visible (count carefully; if items are stacked or partly hidden give your best estimate). '
        . 'Ignore people, shelves, and non-sale items. If nothing sellable is visible return an empty list. '
        . 'Reply with ONLY JSON: {"items":[{"name":"string","quantity":integer}]}';

    $info = @getimagesize($srcPath);
    if (!$info || !isset($allowed[$info[2]])) {
        abort(422, $displayName . ' is not a supported image (use JPG, PNG, WEBP or GIF)');
    }
    $ext = $allowed[$info[2]];
    $fname = $store['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $saved = $isUploaded ? move_uploaded_file($srcPath, $dir . '/' . $fname) : (@rename($srcPath, $dir . '/' . $fname) || (@copy($srcPath, $dir . '/' . $fname) && @unlink($srcPath)));
    if (!$saved) {
        abort(500, 'Could not save the uploaded image');
    }
    $path = 'uploads/' . $fname;
    $uploadId = insert('INSERT INTO ai_uploads (store_id, image_path) VALUES (?,?)', [$store['id'], $path]);

    try {
        $text = ai_chat($system, [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'mime' => $mimes[$ext], 'data' => base64_encode(file_get_contents($dir . '/' . $fname))],
                ['type' => 'text', 'text' => 'List the products in this photo with counts.'],
            ],
        ]], 1200);
    } catch (HttpError $e) {
        q("UPDATE ai_uploads SET status = 'failed' WHERE id = ?", [$uploadId]);
        throw $e;
    }
    $parsed = parse_ai_json($text);
    q("UPDATE ai_uploads SET status = ?, raw_response = ? WHERE id = ?",
        [$parsed ? 'processed' : 'failed', json_encode($parsed ?: ['raw' => $text]), $uploadId]);

    $items = [];
    foreach (($parsed && isset($parsed['items']) && is_array($parsed['items'])) ? $parsed['items'] : [] as $it) {
        $name = isset($it['name']) ? mb_substr(trim((string) $it['name']), 0, 190) : '';
        if ($name === '') {
            continue;
        }
        $qty = max(1, (int) (isset($it['quantity']) ? $it['quantity'] : 1));
        $itemId = insert('INSERT INTO ai_upload_items (upload_id, suggested_name, suggested_qty) VALUES (?,?,?)', [$uploadId, $name, $qty]);
        $match = row('SELECT id, name, quantity, cost_price, sale_price FROM products WHERE store_id = ? AND is_active = 1 AND LOWER(name) = LOWER(?)', [$store['id'], $name]);
        $items[] = ['id' => $itemId, 'name' => $name, 'quantity' => $qty, 'match' => $match];
    }
    return ['upload_id' => $uploadId, 'image' => $path, 'ok' => (bool) $parsed, 'items' => $items];
}

function ai_scan($c)
{
    $files = normalize_files('images');
    if (!$files) {
        abort(422, 'Choose at least one photo');
    }
    if (count($files) > 6) {
        abort(422, 'Upload at most 6 photos at a time');
    }
    $results = [];
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            abort(422, 'Upload failed for ' . $f['name'] . ' (the file may be too large)');
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            abort(413, $f['name'] . ' is larger than 5MB');
        }
        $results[] = ai_scan_image($c['store'], $f['tmp'], $f['name'], true);
    }
    respond(['uploads' => $results]);
}

// Turn (edited) AI suggestions into products / stock.
function ai_confirm($c)
{
    respond(svc_stock_from_ai($c['store']['id'], isset(input()['items']) ? input()['items'] : []));
}

route('GET', '/products', 'products_list');
route('POST', '/products', 'products_create');
route('PUT', '/products/{id}', 'products_update');
route('DELETE', '/products/{id}', 'products_delete');
route('POST', '/products/{id}/stock', 'products_stock');
route('POST', '/ai/scan', 'ai_scan');
route('POST', '/ai/confirm', 'ai_confirm');
