<?php
// Auth, store types, stores.

const CURRENCIES = ['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR', 'XOF', 'UGX', 'TZS'];

function user_stores($uid)
{
    return rows('SELECT s.id, s.name, s.currency, s.store_type_id, t.name AS type_name
                 FROM stores s JOIN store_types t ON t.id = s.store_type_id
                 WHERE s.user_id = ? ORDER BY s.id', [$uid]);
}

function create_store($uid, $name, $typeId, $currency)
{
    if (!val('SELECT id FROM store_types WHERE id = ?', [$typeId])) {
        abort(422, 'Please choose a store type');
    }
    $currency = in_array($currency, CURRENCIES, true) ? $currency : 'NGN';
    return insert('INSERT INTO stores (user_id, store_type_id, name, currency) VALUES (?,?,?,?)', [$uid, $typeId, $name, $currency]);
}

function auth_register($c)
{
    $name = str_in('name', 120, true);
    $email = strtolower(str_in('email', 190, true));
    $pass = (string) (input()['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        abort(422, 'Enter a valid email address');
    }
    if (strlen($pass) < 6) {
        abort(422, 'Password must be at least 6 characters');
    }
    if (val('SELECT id FROM users WHERE email = ?', [$email])) {
        abort(409, 'An account with this email already exists');
    }
    $storeName = str_in('store_name', 150, true);
    $typeId = (int) (input()['store_type_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $uid = insert('INSERT INTO users (name, email, phone, password_hash) VALUES (?,?,?,?)',
            [$name, $email, str_in('phone', 30) ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        create_store($uid, $storeName, $typeId, str_in('currency', 3));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    respond(['token' => make_token($uid), 'user' => row('SELECT id, name, email, phone FROM users WHERE id = ?', [$uid]), 'stores' => user_stores($uid)], 201);
}

function auth_login($c)
{
    $email = strtolower(str_in('email', 190, true));
    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$u || !password_verify((string) (input()['password'] ?? ''), $u['password_hash'])) {
        abort(401, 'Incorrect email or password');
    }
    respond(['token' => make_token($u['id']), 'user' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone']], 'stores' => user_stores($u['id'])]);
}

function auth_me($c)
{
    $u = auth_user();
    respond(['user' => $u, 'stores' => user_stores($u['id'])]);
}

function store_types_list($c)
{
    respond(['types' => rows('SELECT id, name FROM store_types ORDER BY name = "Other", name')]);
}

function stores_create($c)
{
    $id = create_store($c['user']['id'], str_in('name', 150, true), (int) (input()['store_type_id'] ?? 0), str_in('currency', 3));
    respond(['stores' => user_stores($c['user']['id']), 'id' => $id], 201);
}

function stores_update($c)
{
    $s = $c['store'];
    $typeId = (int) (input()['store_type_id'] ?? $s['store_type_id']);
    $cur = str_in('currency', 3) ?: $s['currency'];
    if (!val('SELECT id FROM store_types WHERE id = ?', [$typeId])) {
        abort(422, 'Invalid store type');
    }
    q('UPDATE stores SET name = ?, store_type_id = ?, currency = ? WHERE id = ?', [
        str_in('name', 150) ?: $s['name'], $typeId, in_array($cur, CURRENCIES, true) ? $cur : $s['currency'], $s['id'],
    ]);
    respond(['stores' => user_stores($c['user']['id'])]);
}

route('POST', '/auth/register', 'auth_register', false);
route('POST', '/auth/login', 'auth_login', false);
route('GET', '/auth/me', 'auth_me', false); // resolves the user itself (no store required)
route('GET', '/store-types', 'store_types_list', false);
route('POST', '/stores', 'stores_create');
route('PUT', '/stores/current', 'stores_update');
