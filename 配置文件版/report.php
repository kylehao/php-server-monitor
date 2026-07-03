<?php
/*
 * ==========================================
 * 休闲探针 - 数据接收端
 * 版本：2.0
 * 说明：接收客户端 POST 的数据并写入 SQLite 数据库。
 *       配置从 report.json 读取
 * ==========================================
 */

date_default_timezone_set('UTC');

// ---------- 数据库路径 ----------
$DB_FILE = __DIR__ . "/data/status_v2_xxxxxxxxxxxxxxxxxxxx.db";
if (!is_dir(__DIR__ . "/data")) {
    mkdir(__DIR__ . "/data", 0755, true);
}
$db = new SQLite3($DB_FILE);

// ---------- 配置文件路径 ----------
$CONFIG_FILE = __DIR__ . "/data/report_xxxxxxxxxxxxxxxxxxxx.json";

// ---------- 读取配置函数 ----------
function load_report_config() {
    global $CONFIG_FILE;
    
    if (!file_exists($CONFIG_FILE)) {
        // 如果配置文件不存在，创建默认配置
        $default_config = ['servers' => []];
        file_put_contents($CONFIG_FILE, json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default_config;
    }
    
    $content = file_get_contents($CONFIG_FILE);
    $config = json_decode($content, true);
    
    if ($config === null) {
        // JSON解析失败，返回空配置
        return ['servers' => []];
    }
    
    return $config;
}

// ---------- 保存配置函数 ----------
function save_report_config($config) {
    global $CONFIG_FILE;
    return file_put_contents($CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ---------- 加载配置 ----------
$config = load_report_config();
$servers = $config['servers'] ?? [];

// 构建 TOKENS 数组（用于兼容原有代码）
$TOKENS = [];
foreach ($servers as $server) {
    $token_original = $server['token_original'] ?? '';
    $name = $server['name'] ?? '';
    if (!empty($token_original) && !empty($name)) {
        $TOKENS[$token_original] = $name;
    }
}

// ---------- 初始化表结构 ----------
$db->exec("CREATE TABLE IF NOT EXISTS node_status (
    token_md5 TEXT PRIMARY KEY,
    name TEXT,
    cpu REAL,
    mem REAL,
    disk REAL,
    last_rx INTEGER,
    last_tx INTEGER,
    ping_mobile INTEGER,
    ping_unicom INTEGER,
    ping_telecom INTEGER,
    ping_att INTEGER,
    updated_at INTEGER
)");

// 兼容旧表添加新列
$existing = [];
$res = $db->query("PRAGMA table_info(node_status)");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $row['name'];
}
if (!in_array('cpu_cores', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN cpu_cores INTEGER DEFAULT 0");
}
if (!in_array('mem_total_mb', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN mem_total_mb INTEGER DEFAULT 0");
}
if (!in_array('disk_total_gb', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN disk_total_gb INTEGER DEFAULT 0");
}
if (!in_array('os_info', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN os_info TEXT DEFAULT ''");
}
if (!in_array('uptime_seconds', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN uptime_seconds INTEGER DEFAULT 0");
}

$db->exec("CREATE TABLE IF NOT EXISTS traffic_monthly (
    token_md5 TEXT,
    month_str TEXT,
    rx_total INTEGER DEFAULT 0,
    tx_total INTEGER DEFAULT 0,
    PRIMARY KEY (token_md5, month_str)
)");

$db->exec("CREATE TABLE IF NOT EXISTS node_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_md5 TEXT NOT NULL,
    recorded_at INTEGER NOT NULL,
    cpu REAL, mem REAL, disk REAL,
    ping_mobile INTEGER, ping_unicom INTEGER,
    ping_telecom INTEGER, ping_att INTEGER,
    rx_delta INTEGER DEFAULT 0,
    tx_delta INTEGER DEFAULT 0
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_history_token_time ON node_history(token_md5, recorded_at)");

// ==========================================

// 简单的 JSON 响应函数
function json_response($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

// 仅接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] != 'POST') json_response(["ok"=>false, "msg"=>"仅支持 POST"], 403);

$body = file_get_contents("php://input");
$data = json_decode($body, true);
if (!$data) json_response(["ok"=>false, "msg"=>"JSON 解析失败"], 400);

$token = $data['token'] ?? '';
if (!isset($TOKENS[$token])) json_response(["ok"=>false, "msg"=>"无效 token"], 403);

$name = $TOKENS[$token];
$token_md5 = md5($token); // 用于主键
$now = time();
$current_month = date('Y-m');

// 取出客户端发送的字段
$cpu = floatval($data['cpu'] ?? 0);
$mem = floatval($data['mem'] ?? 0);
$disk = floatval($data['disk'] ?? 0);
$new_rx = intval($data['rx'] ?? 0);
$new_tx = intval($data['tx'] ?? 0);
$p_mobile = intval($data['ping_mobile'] ?? -1);
$p_unicom = intval($data['ping_unicom'] ?? -1);
$p_telecom = intval($data['ping_telecom'] ?? -1);
$p_att = intval($data['ping_att'] ?? -1);
$cpu_cores = intval($data['cpu_cores'] ?? 0);
$mem_total_mb = intval($data['mem_total_mb'] ?? 0);
$disk_total_gb = intval($data['disk_total_gb'] ?? 0);
$os_info = $data['os_info'] ?? '';
$uptime_seconds = intval($data['uptime_seconds'] ?? 0);

// 计算流量增量（避免重复计数）
$stmt = $db->prepare("SELECT last_rx, last_tx FROM node_status WHERE token_md5 = :token_md5");
$stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
$res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

$delta_rx = 0;
$delta_tx = 0;
if ($res) {
    $delta_rx = ($new_rx >= $res['last_rx']) ? ($new_rx - $res['last_rx']) : $new_rx;
    $delta_tx = ($new_tx >= $res['last_tx']) ? ($new_tx - $res['last_tx']) : $new_tx;
}

// 更新最新状态
$db->exec("INSERT INTO node_status (token_md5, name, cpu, mem, disk, last_rx, last_tx, ping_mobile, ping_unicom, ping_telecom, ping_att, updated_at, cpu_cores, mem_total_mb, disk_total_gb, os_info, uptime_seconds)
    VALUES ('$token_md5', '$name', $cpu, $mem, $disk, $new_rx, $new_tx, $p_mobile, $p_unicom, $p_telecom, $p_att, $now, $cpu_cores, $mem_total_mb, $disk_total_gb, '$os_info', $uptime_seconds)
    ON CONFLICT(token_md5) DO UPDATE SET
    cpu=$cpu, mem=$mem, disk=$disk, last_rx=$new_rx, last_tx=$new_tx,
    ping_mobile=$p_mobile, ping_unicom=$p_unicom, ping_telecom=$p_telecom, ping_att=$p_att, updated_at=$now,
    cpu_cores=$cpu_cores, mem_total_mb=$mem_total_mb, disk_total_gb=$disk_total_gb,
    os_info='$os_info', uptime_seconds=$uptime_seconds");

// 写入历史记录
$db->exec("INSERT INTO node_history (token_md5, recorded_at, cpu, mem, disk, ping_mobile, ping_unicom, ping_telecom, ping_att, rx_delta, tx_delta)
    VALUES ('$token_md5', $now, $cpu, $mem, $disk, $p_mobile, $p_unicom, $p_telecom, $p_att, $delta_rx, $delta_tx)");

// 更新月度流量
if ($delta_rx > 0 || $delta_tx > 0) {
    $db->exec("INSERT INTO traffic_monthly (token_md5, month_str, rx_total, tx_total)
        VALUES ('$token_md5', '$current_month', $delta_rx, $delta_tx)
        ON CONFLICT(token_md5, month_str) DO UPDATE SET rx_total = rx_total + $delta_rx, tx_total = tx_total + $delta_tx");
}

// 清理 24 小时前的历史数据
$db->exec("DELETE FROM node_history WHERE recorded_at < " . ($now - 86400));

json_response(["ok"=>true]);