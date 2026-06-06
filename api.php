<?php
// api.php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $db = new PDO('sqlite:finance.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA foreign_keys = ON;");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    $config = $db->query("SELECT value FROM config WHERE key = 'auth_password'")->fetch();
    if ($config && password_verify($password, $config['value'])) {
        $_SESSION['auth'] = true;
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '密码错误']);
    }
    exit;
}

if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'logout': session_destroy(); echo json_encode(['success' => true]); break;
    case 'dashboard': handleDashboard($db); break;
    case 'trends': handleTrends($db); break;
    case 'account': handleAccount($db, $method); break;
    case 'asset': handleAsset($db, $method); break;
    case 'snapshot': handleSnapshot($db, $method); break;
    case 'config': handleConfig($db, $method); break;
    default: http_response_code(404); break;
}

function getExchangeRates($db) {
    $stmt = $db->query("SELECT key, value FROM config WHERE key LIKE 'rate_%'");
    $rates = ['rate_cny' => 1.0];
    while ($row = $stmt->fetch()) { $rates[$row['key']] = (float)$row['value']; }
    return $rates;
}

function handleDashboard($db) {
    $rates = getExchangeRates($db);
    $usd = $rates['rate_usd'] ?? 7.25;
    $hkd = $rates['rate_hkd'] ?? 0.93;

    $max_month = $db->query("SELECT MAX(snapshot_date) as max_month FROM asset_snapshots")->fetch()['max_month'] ?? date('Y-m');

    $sql = "SELECT 
                a.*, ac.name as account_name, 
                IFNULL(s.last_nav, 0) as market_value,
                CASE 
                    WHEN s.init_nav IS NOT NULL THEN s.init_nav
                    ELSE IFNULL((SELECT prev.last_nav FROM asset_snapshots prev WHERE prev.asset_id = a.id AND prev.snapshot_date = strftime('%Y-%m', :month || '-01', '-1 month')), 0)
                END as init_nav,
                (IFNULL(s.last_nav, 0) - CASE 
                    WHEN s.init_nav IS NOT NULL THEN s.init_nav
                    ELSE IFNULL((SELECT prev.last_nav FROM asset_snapshots prev WHERE prev.asset_id = a.id AND prev.snapshot_date = strftime('%Y-%m', :month || '-01', '-1 month')), 0)
                END) as profit_loss,
                CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END as exchange_rate,
                (CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END * IFNULL(s.last_nav, 0)) as cny_market_value,
                (CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END * (IFNULL(s.last_nav, 0) - CASE 
                    WHEN s.init_nav IS NOT NULL THEN s.init_nav
                    ELSE IFNULL((SELECT prev.last_nav FROM asset_snapshots prev WHERE prev.asset_id = a.id AND prev.snapshot_date = strftime('%Y-%m', :month || '-01', '-1 month')), 0)
                END)) as cny_profit_loss
            FROM assets a
            JOIN accounts ac ON a.account_id = ac.id
            LEFT JOIN asset_snapshots s ON a.id = s.asset_id AND s.snapshot_date = :month
            ORDER BY ac.sort_order ASC, a.sort_order ASC, a.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(['usd' => $usd, 'hkd' => $hkd, 'month' => $max_month]);
    
    echo json_encode([
        'success' => true,
        'current_month' => $max_month,
        'rates' => ['USD' => $usd, 'HKD' => $hkd],
        'accounts' => $db->query("SELECT * FROM accounts ORDER BY sort_order ASC, id DESC")->fetchAll(),
        'assets_raw' => $stmt->fetchAll()
    ]);
}

function handleTrends($db) {
    $rates = getExchangeRates($db);
    $usd = $rates['rate_usd'] ?? 7.25;
    $hkd = $rates['rate_hkd'] ?? 0.93;

    $sql = "SELECT t.snapshot_date, SUM(t.cny_market_value) as total_asset, SUM(t.cny_profit_loss) as total_profit
            FROM (
                SELECT s.snapshot_date,
                    s.last_nav * (CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END) as cny_market_value,
                    (s.last_nav - CASE 
                        WHEN s.init_nav IS NOT NULL THEN s.init_nav
                        ELSE IFNULL((SELECT prev.last_nav FROM asset_snapshots prev WHERE prev.asset_id = a.id AND prev.snapshot_date = strftime('%Y-%m', s.snapshot_date || '-01', '-1 month')), 0)
                    END) * (CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END) as cny_profit_loss
                FROM asset_snapshots s
                JOIN assets a ON s.asset_id = a.id
            ) t
            GROUP BY t.snapshot_date ORDER BY t.snapshot_date ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute(['usd' => $usd, 'hkd' => $hkd]);
    echo json_encode(['success' => true, 'trends' => $stmt->fetchAll()]);
}

function handleAccount($db, $method) {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => $db->query("SELECT * FROM accounts ORDER BY sort_order ASC, id DESC")->fetchAll()]);
    } elseif ($method === 'POST') {
        $db->prepare("INSERT INTO accounts (name, description, sort_order) VALUES (?, ?, ?)")->execute([$input['name'], $input['description'] ?? '', intval($input['sort_order'] ?? 0)]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'PUT') {
        $db->prepare("UPDATE accounts SET name = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$input['name'], $input['description'], intval($input['sort_order'] ?? 0), $input['id']]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        $db->prepare("DELETE FROM accounts WHERE id = ?")->execute([$_GET['id'] ?? 0]);
        echo json_encode(['success' => true]);
    }
}

function handleAsset($db, $method) {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => $db->query("SELECT a.*, ac.name as account_name FROM assets a JOIN accounts ac ON a.account_id = ac.id ORDER BY ac.sort_order ASC, a.sort_order ASC, a.id DESC")->fetchAll()]);
    } elseif ($method === 'POST') {
        $db->prepare("INSERT INTO assets (account_id, name, type, risk_level, currency, sort_order) VALUES (?, ?, ?, ?, ?, ?)")->execute([$input['account_id'], $input['name'], $input['type'], $input['risk_level'], $input['currency'], intval($input['sort_order'] ?? 0)]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'PUT') {
        $db->prepare("UPDATE assets SET account_id = ?, name = ?, type = ?, risk_level = ?, currency = ?, sort_order = ? WHERE id = ?")->execute([$input['account_id'], $input['name'], $input['type'], $input['risk_level'], $input['currency'], intval($input['sort_order'] ?? 0), $input['id']]);
        echo json_encode(['success' => true]);
    } elseif ($method === 'DELETE') {
        $db->prepare("DELETE FROM assets WHERE id = ?")->execute([$_GET['id'] ?? 0]);
        echo json_encode(['success' => true]);
    }
}

function handleSnapshot($db, $method) {
    if ($method === 'GET') {
        $month = $_GET['month'] ?? date('Y-m');
        $rates = getExchangeRates($db);
        $usd = $rates['rate_usd'] ?? 7.25;
        $hkd = $rates['rate_hkd'] ?? 0.93;

        $sql = "SELECT a.id as asset_id, a.account_id, a.name as asset_name, a.type, a.risk_level, a.currency, ac.name as account_name,
                    IFNULL(s.last_nav, 0) as last_nav,
                    CASE 
                        WHEN s.init_nav IS NOT NULL THEN s.init_nav
                        ELSE IFNULL((SELECT prev.last_nav FROM asset_snapshots prev WHERE prev.asset_id = a.id AND prev.snapshot_date = strftime('%Y-%m', :month || '-01', '-1 month')), 0)
                    END as init_nav,
                    CASE a.currency WHEN 'USD' THEN :usd WHEN 'HKD' THEN :hkd ELSE 1.0 END as exchange_rate
                FROM assets a JOIN accounts ac ON a.account_id = ac.id
                LEFT JOIN asset_snapshots s ON a.id = s.asset_id AND s.snapshot_date = :month
                ORDER BY ac.sort_order ASC, a.sort_order ASC, a.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'usd' => $usd, 'hkd' => $hkd]);
        echo json_encode(['success' => true, 'month' => $month, 'data' => $stmt->fetchAll()]);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $month = $input['month'] ?? '';
        
        $rows = isset($input['asset_id']) ? [$input] : ($input['snapshots'] ?? []);

        $db->beginTransaction();
        try {
            $sql = "INSERT INTO asset_snapshots (asset_id, snapshot_date, init_nav, last_nav) 
                    VALUES (:asset_id, :snapshot_date, :init_nav, :last_nav)
                    ON CONFLICT(asset_id, snapshot_date) DO UPDATE SET 
                        init_nav = excluded.init_nav, last_nav = excluded.last_nav";
            $stmt = $db->prepare($sql);
            foreach ($rows as $row) {
                $stmt->execute([
                    'asset_id' => $row['asset_id'], 'snapshot_date' => $month,
                    'init_nav' => $row['init_nav'] ?? 0, 'last_nav' => $row['last_nav'] ?? 0
                ]);
            }
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

function handleConfig($db, $method) {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($method === 'GET') {
        $rows = $db->query("SELECT key, value FROM config WHERE key != 'auth_password'")->fetchAll();
        $config = [];
        foreach ($rows as $r) { $config[$r['key']] = $r['value']; }
        echo json_encode(['success' => true, 'config' => $config]);
    } elseif ($method === 'POST') {
        if (isset($input['rate_usd'])) $db->prepare("REPLACE INTO config (key, value) VALUES ('rate_usd', ?)")->execute([$input['rate_usd']]);
        if (isset($input['rate_hkd'])) $db->prepare("REPLACE INTO config (key, value) VALUES ('rate_hkd', ?)")->execute([$input['rate_hkd']]);
        if (!empty($input['new_password'])) {
            $db->prepare("REPLACE INTO config (key, value) VALUES ('auth_password', ?)")->execute([password_hash($input['new_password'], PASSWORD_DEFAULT)]);
        }
        echo json_encode(['success' => true]);
    }
}