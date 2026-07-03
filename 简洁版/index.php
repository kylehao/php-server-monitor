<?php
/*
 * ==========================================
 * 休闲探针 - 展示面板
 * 版本：1.5
 * 作者：你的昵称（可修改）
 * 说明：本文件为服务器状态展示页，包含实时数据刷新、历史延迟图、自定义标签、流量进度条等。
 * 所有可自定义的配置集中在文件头部的"用户配置区"。
 * ==========================================
 */

date_default_timezone_set('Asia/Shanghai');

// ---------- 数据库路径 ----------
$DB_FILE = __DIR__ . "/data/status_v2_xxxxxxxxxxxxxxxxxxxx.db";
if (!is_dir(__DIR__ . "/data")) {
    mkdir(__DIR__ . "/data", 0755, true);
}
$db = new SQLite3($DB_FILE);

// ---------- 初始化表结构（防止首次使用报错） ----------
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

// 兼容旧表自动添加新列
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

/*
 * ==========================================
 *  👤 用户配置区（请根据你的服务器信息修改）
 * ==========================================
 */

// ---------- 1. 节点定义 ----------
// 格式： '客户端 token 的 MD5 值（小写）' => '显示名称'
// 如何获取 MD5：Linux 下执行 echo -n "你的token原文" | md5sum
$TOKENS = [
"MD5加密小写1" => "Oracle ARM Singapore",
"MD5加密小写2" => "Oracle ARM Hyderabad",
"MD5加密小写3" => "Oracle AMD Hyderabad",
"MD5加密小写4" => "Oracle AMD Sanjose",
"MD5加密小写5" => "HomeCloud",
];

// ---------- 2. 服务器分组配置 ----------
// 格式： '组名' => ['节点名称1', '节点名称2', ...]
// 可以按地域、用途、类型等任意方式分组
$SERVER_GROUPS = [
    '海外节点' => [
        'Oracle ARM Singapore',
        'Oracle ARM Hyderabad',
        'Oracle AMD Hyderabad',
        'Oracle AMD Sanjose',
    ],
    '国内节点' => [
        'HomeCloud',
    ]
];

// 分组图标（可选）
$GROUP_ICONS = [
    '海外节点' => 'fa-globe-asia',
    '国内节点' => 'fa-house-chimney',
];

// ---------- 3. 流量配额（单位：GB） ----------
$TRAFFIC_LIMIT = [
    "Oracle ARM Singapore" => 1000,
    "Oracle ARM Hyderabad" => 1000,
    "Oracle AMD Hyderabad" => 1000,
    "Oracle AMD Sanjose"  => 1000,
    "HomeCloud"           => 1000,
];

// 流量统计模式：'both' = 双向 (RX+TX), 'rx' = 仅入站, 'tx' = 仅出站
$TRAFFIC_MODE = 'both';

// ---------- 4. 自定义标签（每个节点最多5个，建议字符简短） ----------
$CUSTOM_TAGS = [
    "Oracle ARM Singapore" => ["🇸🇬 新加坡", "ARM 2C"],
    "Oracle ARM Hyderabad" => ["🇮🇳 印度", "ARM 2C"],
    "Oracle AMD Hyderabad" => ["🇮🇳 印度", "AMD 1C"],
    "Oracle AMD Sanjose"  => ["🇺🇸 美西", "AMD 1C"],
    "HomeCloud"           => ["🏠 家庭", "CN2 主力"],
];

// ---------- 5. 到期时间（格式：YYYY-MM-DD，留空则不显示） ----------
$EXPIRE_DATES = [
    "Oracle ARM Singapore" => "",
    "Oracle ARM Hyderabad" => "",
    "Oracle AMD Hyderabad" => "",
    "Oracle AMD Sanjose"  => "",
    "HomeCloud"           => "",
];

// ---------- 6. 其他文本标签（如价格、带宽等） ----------
$OTHER_TAGS = [
    "Oracle ARM Singapore" => ["$5/mo", "1000Mbps"],
    "Oracle ARM Hyderabad" => ["$5/mo"],
    "Oracle AMD Hyderabad" => ["$5/mo"],
    "Oracle AMD Sanjose"  => ["$8/mo", "50TB"],
    "HomeCloud"           => ["$80/yr", "500Mbps"],
];

/*
 * ==========================================
 *  以下为程序逻辑，如无特殊需求无需修改
 * ==========================================
 */

// 国旗对应的国家代码（用于 flag-icon-css）
function get_flag_class($name) {
    $map = [
        "Oracle ARM Singapore" => "sg",
        "Oracle ARM Hyderabad" => "in",
        "Oracle AMD Hyderabad" => "in",
        "Oracle AMD Sanjose"  => "us",
        "HomeCloud"           => "cn",
    ];
    return $map[$name] ?? "";
}

// 缩短系统名称
function short_os($full_os) {
    if (stripos($full_os, 'Ubuntu') !== false) return 'Ubuntu';
    if (stripos($full_os, 'Debian') !== false) return 'Debian';
    return $full_os;
}

// 格式化字节
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// 格式化内存总量
function format_mem($mb) {
    if ($mb >= 1024) return round($mb/1024, 1) . 'GB';
    return $mb . 'MB';
}

// 在线时长简写
function format_uptime_short($seconds) {
    $days = floor($seconds / 86400);
    if ($days >= 1) return $days . '天';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours . '小时 ' . $minutes . '分钟';
}

// 到期倒计时
function days_until($date_str) {
    if (empty($date_str)) return '';
    $target = strtotime($date_str);
    if ($target === false) return '';
    $diff = $target - time();
    $days = floor($diff / 86400);
    if ($days < 0) return '已过期';
    if ($days == 0) return '今日到期';
    return '剩' . $days . '天';
}

// 获取节点状态（缓存用）
function get_node_status($db, $token_md5) {
    $stmt = $db->prepare("SELECT * FROM node_status WHERE token_md5 = :token_md5");
    $stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

// 获取节点流量
function get_node_traffic($db, $token_md5, $month) {
    $stmt = $db->prepare("SELECT rx_total, tx_total FROM traffic_monthly WHERE token_md5=:t AND month_str=:m");
    $stmt->bindValue(':t', $token_md5, SQLITE3_TEXT);
    $stmt->bindValue(':m', $month, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

// ---------- 计算服务器统计信息 ----------
function get_server_stats($db, $TOKENS) {
    $total = count($TOKENS);
    $online = 0;
    $offline = 0;
    
    foreach ($TOKENS as $token_md5 => $name) {
        $node = get_node_status($db, $token_md5);
        if ($node && (time() - $node['updated_at']) < 300) {
            $online++;
        } else {
            $offline++;
        }
    }
    
    return ['total' => $total, 'online' => $online, 'offline' => $offline];
}

$stats = get_server_stats($db, $TOKENS);

// ---------- AJAX 实时数据接口 ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'realtime') {
    $result = [];
    $total_rx = 0; $total_tx = 0;
    $current_month = date('Y-m');
    $online_count = 0;
    $offline_count = 0;

    foreach ($TOKENS as $token_md5 => $name) {
        $node = get_node_status($db, $token_md5);
        if (!$node) {
            $result[] = ['token_md5' => $token_md5, 'online' => false, 'name' => $name];
            $offline_count++;
            continue;
        }
        $is_online = (time() - $node['updated_at']) < 300;
        if ($is_online) {
            $online_count++;
        } else {
            $offline_count++;
        }
        
        $traffic = get_node_traffic($db, $token_md5, $current_month);
        $rx = $traffic['rx_total'] ?? 0;
        $tx = $traffic['tx_total'] ?? 0;
        $total_rx += $rx; $total_tx += $tx;

        $result[] = [
            'token_md5' => $token_md5,
            'online' => $is_online,
            'cpu' => (float)$node['cpu'],
            'mem' => (float)$node['mem'],
            'disk' => (float)$node['disk'],
            'cpu_cores' => (int)$node['cpu_cores'],
            'mem_total_mb' => (int)$node['mem_total_mb'],
            'disk_total_gb' => (int)$node['disk_total_gb'],
            'os_info' => $node['os_info'] ?? '',
            'rx_total' => $rx,
            'tx_total' => $tx,
            'uptime_seconds' => (int)$node['uptime_seconds']
        ];
    }
    header('Content-Type: application/json');
    echo json_encode([
        'nodes' => $result,
        'total_rx' => $total_rx,
        'total_tx' => $total_tx,
        'current_time' => date('Y-m-d H:i:s'),
        'stats' => [
            'total' => count($TOKENS),
            'online' => $online_count,
            'offline' => $offline_count
        ]
    ]);
    exit;
}

// ---------- 页面 HTML 开始 ----------
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>听松阁探针</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icon-css@3.5.0/css/flag-icon.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* ========== 背景固定 ========== */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        
        body {
            color: #fff;
            font-family: 'Segoe UI', Arial, sans-serif;
            /* 背景固定 */
            background: #111827;
            background-image: url('https://img10.360buyimg.com/ddimg/jfs/t1/453252/16/14264/106854/6a37426aF81bc450f/00155a036eee5406.jpg');
            background-repeat: no-repeat;
            background-position: center center;
            background-size: cover;
            background-attachment: fixed;
            background-color: #111827;
            overflow: hidden;
            height: 100vh;
        }
        
        /* ========== 主容器 - 全屏滚动 ========== */
        .app-wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }
        
        /* ========== 固定头部 ========== */
        .header {
            flex-shrink: 0;
            padding: 15px 20px;
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.3);
            z-index: 100;
        }
        
        .header .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .header-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-title h1 {
            margin: 0;
            font-size: 24px;
            color: #fff;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .header-title h1 i {
            color: #22c55e;
        }
        
        /* ========== 统计信息栏 ========== */
        .stats-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            background: rgba(0, 0, 0, 0.3);
            padding: 6px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .stats-bar .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #d1d5db;
        }
        .stats-bar .stat-item .stat-number {
            font-weight: 700;
            font-size: 15px;
        }
        .stats-bar .stat-item .stat-label {
            color: #9ca3af;
            font-size: 12px;
        }
        .stats-bar .stat-item.total .stat-number { color: #60a5fa; }
        .stats-bar .stat-item.online .stat-number { color: #22c55e; }
        .stats-bar .stat-item.offline .stat-number { color: #ef4444; }
        .stats-bar .stat-divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.1);
        }
        
        /* ========== 视图切换 ========== */
        .view-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .view-toggle button {
            background: rgba(255,255,255,0.05);
            color: #9ca3af;
            border: 1px solid rgba(255,255,255,0.05);
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        .view-toggle button:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .view-toggle button.active {
            background: #22c55e;
            color: #fff;
            border-color: #22c55e;
        }
        
        /* ========== 流量信息 ========== */
        .total-traffic {
            font-size: 13px;
            background: rgba(0, 0, 0, 0.3);
            padding: 6px 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            flex-wrap: wrap;
        }
        .total-traffic .time-line { color: #d1d5db; }
        .total-traffic .traffic-row { display: flex; gap: 12px; }
        .total-traffic .rx { color: #34d399; }
        .total-traffic .tx { color: #60a5fa; }
        
        /* ========== 滚动内容区 ========== */
        .scroll-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 0 40px 0;
            /* 滚动条美化 */
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.2) transparent;
        }
        .scroll-content::-webkit-scrollbar {
            width: 6px;
        }
        .scroll-content::-webkit-scrollbar-track {
            background: transparent;
        }
        .scroll-content::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 3px;
        }
        .scroll-content::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .scroll-content .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        /* ========== 分组样式 ========== */
        .group-section {
            margin-bottom: 25px;
        }
        .group-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 18px;
            background: rgba(31, 41, 55, 0.7);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #22c55e;
        }
        .group-header .group-icon {
            font-size: 18px;
            color: #22c55e;
        }
        .group-header .group-name {
            font-size: 17px;
            font-weight: 600;
            color: #fff;
        }
        /* 分组统计信息 - 在名称后面显示 */
        .group-header .group-stats {
            font-size: 13px;
            color: #9ca3af;
            margin-left: 8px;
            font-weight: normal;
        }
        .group-header .group-stats .stat-total {
            color: #60a5fa;
        }
        .group-header .group-stats .stat-online {
            color: #22c55e;
        }
        .group-header .group-stats .stat-offline {
            color: #ef4444;
        }
        .group-header .group-stats .stat-divider {
            color: #4b5563;
            margin: 0 4px;
        }
        .group-header .group-count {
            font-size: 12px;
            color: #9ca3af;
            margin-left: auto;
            background: rgba(255,255,255,0.08);
            padding: 2px 10px;
            border-radius: 20px;
        }
        /* 隐藏旧的 group-status */
        .group-header .group-status {
            display: none;
        }
        
        /* ========== 卡片网格 ========== */
        .cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        
        .cards-grid.list-view {
            display: block;
        }
        .cards-grid.list-view .card {
            display: grid;
            grid-template-columns: 180px 1fr 110px;
            gap: 12px;
            align-items: center;
            padding: 10px 16px;
            border-left-width: 4px;
            margin-bottom: 8px;
        }
        .cards-grid.list-view .card .node-header {
            margin-bottom: 0;
        }
        .cards-grid.list-view .card .card-body {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 8px 15px;
            align-items: center;
        }
        .cards-grid.list-view .card .progress-item {
            flex: 1;
            min-width: 80px;
        }
        .cards-grid.list-view .card .progress-item .progress-total {
            width: 40px;
            font-size: 11px;
        }
        .cards-grid.list-view .card .info-row {
            flex-wrap: wrap;
            padding: 4px 10px;
            font-size: 11px;
        }
        .cards-grid.list-view .card .tags-row {
            margin-top: 0;
        }
        .cards-grid.list-view .card .offline-placeholder {
            padding: 8px;
        }
        .cards-grid.list-view .card .ping-chart-box {
            display: none;
        }
        .cards-grid.list-view .card .progress-item .progress-label {
            width: 35px;
            font-size: 11px;
        }
        .cards-grid.list-view .card .progress-bar-container {
            height: 12px;
        }
        .cards-grid.list-view .card .progress-text {
            font-size: 9px;
        }
        .cards-grid.list-view .node-title {
            font-size: 13px;
        }
        .cards-grid.list-view .status-badge {
            font-size: 11px;
        }
        
        @media (max-width: 800px) {
            .header-inner {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .header-left {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .header-title h1 {
                font-size: 20px;
            }
            .stats-bar {
                justify-content: center;
                padding: 4px 12px;
            }
            .total-traffic {
                justify-content: center;
                font-size: 12px;
            }
            .cards-grid {
                grid-template-columns: 1fr;
            }
            .cards-grid.list-view .card {
                grid-template-columns: 1fr;
                gap: 6px;
            }
            .cards-grid.list-view .card .card-body {
                flex-direction: column;
            }
            .group-header {
                flex-wrap: wrap;
            }
            .group-header .group-count {
                margin-left: 0;
            }
            .stats-bar .stat-divider {
                display: none;
            }
            .group-header .group-stats {
                font-size: 12px;
            }
        }
        
        /* ========== 卡片样式 ========== */
        a { color: #6b7280; text-decoration: none; }
        
        .card {
            background: rgba(31, 41, 55, 0.3);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 16px 18px;
            border-left: 5px solid #22c55e;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .card:hover {
            background: rgba(31, 41, 55, 0.85);
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
        }
        .card.node-offline {
            border-left-color: #ef4444;
            opacity: 0.6;
            filter: grayscale(30%);
        }
        .card.node-offline:hover {
            opacity: 0.8;
        }
        
        .node-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .node-title {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
        }
        .flag-icon {
            width: 1.2em;
            height: 1em;
            display: inline-block;
            vertical-align: middle;
        }
        .status-badge {
            font-weight: bold;
            font-size: 13px;
        }
        .online { color: #22c55e; }
        .offline { color: #ef4444; }
        
        .card-body {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .progress-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .progress-label {
            width: 45px;
            font-size: 13px;
            color: #9ca3af;
            flex-shrink: 0;
        }
        .progress-bar-container {
            flex: 1;
            background: rgba(55, 65, 81, 0.6);
            border-radius: 4px;
            height: 18px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s;
        }
        .progress-bar.cpu { background: #60a5fa; }
        .progress-bar.mem { background: #34d399; }
        .progress-bar.disk { background: #fbbf24; }
        .progress-bar.traffic { background: #a78bfa; }
        .progress-text {
            position: absolute;
            top: 0;
            right: 6px;
            height: 100%;
            display: flex;
            align-items: center;
            font-size: 11px;
            color: #fff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }
        .progress-total {
            width: 70px;
            font-size: 12px;
            color: #d1d5db;
            text-align: right;
            flex-shrink: 0;
        }
        
        .ping-chart-box {
            background: rgba(17, 24, 39, 0.5);
            border-radius: 8px;
            padding: 10px;
        }
        .ping-chart-box canvas {
            width: 100% !important;
            height: 140px !important;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(17, 24, 39, 0.5);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            color: #d1d5db;
            flex-wrap: nowrap;
            gap: 8px;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .info-item.os-item {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 1;
        }
        .info-item.uptime-item, .info-item.traffic-item {
            flex-shrink: 0;
        }
        .traffic-item .rx { color: #34d399; }
        .traffic-item .tx { color: #60a5fa; }
        
        .tags-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 6px;
        }
        .tag {
            font-size: 10px;
            background: rgba(55, 65, 81, 0.6);
            color: #d1d5db;
            padding: 2px 8px;
            border-radius: 10px;
            white-space: nowrap;
        }
        .tag.expire {
            background: rgba(185, 28, 28, 0.7);
            color: #fff;
        }
        
        .offline-placeholder {
            text-align: center;
            padding: 20px;
            color: #9ca3af;
            font-size: 14px;
        }
        
        /* ========== Footer ========== */
        footer {
            text-align: center;
            padding: 15px;
            color: rgba(107, 114, 128, 0.7);
            font-size: 12px;
            flex-shrink: 0;
            background: rgba(17, 24, 39, 0.3);
            backdrop-filter: blur(5px);
            border-top: 1px solid rgba(255, 255, 255, 0.03);
        }
        footer a {
            color: rgba(107, 114, 128, 0.8);
        }
        footer a:hover {
            color: #22c55e;
        }
        
        /* ========== 加载动画 ========== */
        .loading {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        .loading i {
            font-size: 30px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <!-- ====== 固定头部 ====== -->
    <div class="header">
        <div class="container">
            <div class="header-inner">
                <div class="header-left">
                    <div class="header-title">
                        <h1><i class="fas fa-server"></i> 听松阁探针</h1>
                    </div>
                    
                    <!-- 统计信息栏 -->
                    <div class="stats-bar" id="stats-bar">
                        <span class="stat-item total">
                            <i class="fas fa-server"></i>
                            <span class="stat-number" id="stat-total"><?php echo $stats['total']; ?></span>
                            <span class="stat-label">总数</span>
                        </span>
                        <span class="stat-divider"></span>
                        <span class="stat-item online">
                            <i class="fas fa-circle" style="color:#22c55e; font-size:8px;"></i>
                            <span class="stat-number" id="stat-online"><?php echo $stats['online']; ?></span>
                            <span class="stat-label">在线</span>
                        </span>
                        <span class="stat-divider"></span>
                        <span class="stat-item offline">
                            <i class="fas fa-circle" style="color:#ef4444; font-size:8px;"></i>
                            <span class="stat-number" id="stat-offline"><?php echo $stats['offline']; ?></span>
                            <span class="stat-label">离线</span>
                        </span>
                    </div>
                </div>
                
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div class="view-toggle">
                        <button id="view-grid" class="active" onclick="setView('grid')">
                            <i class="fas fa-th"></i> 卡片
                        </button>
                        <button id="view-list" onclick="setView('list')">
                            <i class="fas fa-list"></i> 列表
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- 流量信息 -->
            <div class="total-traffic" id="total-traffic" style="margin-top:8px;">
                <span class="time-line"><i class="far fa-clock"></i> <span id="current-time"><?php echo date('Y-m-d H:i:s'); ?></span> CST</span>
                <div class="traffic-row">
                    <span class="rx"><i class="fas fa-download"></i> <span id="rx-value"><?php echo format_bytes($total_rx); ?></span></span>
                    <span class="tx"><i class="fas fa-upload"></i> <span id="tx-value"><?php echo format_bytes($total_tx); ?></span></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ====== 滚动内容区 ====== -->
    <div class="scroll-content" id="scroll-content">
        <div class="container">
            <div id="cards-container">
<?php
$history_scripts = [];
$all_node_data = [];

// 先收集所有节点数据
foreach ($TOKENS as $token_md5 => $name) {
    $node = get_node_status($db, $token_md5);
    $traffic = get_node_traffic($db, $token_md5, date('Y-m'));
    $history_rows = [];
    
    if ($node) {
        $earliest = $db->querySingle("SELECT MIN(recorded_at) FROM node_history WHERE token_md5 = '$token_md5'");
        $now = time();
        $threshold = ($earliest && ($now - $earliest) > 86400) ? $now - 86400 : 0;
        
        $hist_stmt = $db->prepare("SELECT recorded_at, ping_mobile, ping_unicom, ping_telecom, ping_att
                                   FROM node_history
                                   WHERE token_md5 = :token_md5 AND recorded_at >= :threshold
                                   ORDER BY recorded_at ASC");
        $hist_stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
        $hist_stmt->bindValue(':threshold', $threshold, SQLITE3_INTEGER);
        $hist_res = $hist_stmt->execute();
        
        while ($row = $hist_res->fetchArray(SQLITE3_ASSOC)) {
            $history_rows[] = [
                't' => (int)$row['recorded_at'],
                'ping_mobile' => (int)$row['ping_mobile'],
                'ping_unicom' => (int)$row['ping_unicom'],
                'ping_telecom' => (int)$row['ping_telecom'],
                'ping_att' => (int)$row['ping_att'],
            ];
        }
    }
    
    $all_node_data[$token_md5] = [
        'name' => $name,
        'node' => $node,
        'traffic' => $traffic,
        'history' => $history_rows
    ];
    
    if (!empty($history_rows)) {
        $history_scripts[] = "window['hist_{$token_md5}'] = " . json_encode($history_rows, JSON_UNESCAPED_UNICODE) . ";";
    }
}

// 按分组渲染
foreach ($SERVER_GROUPS as $group_name => $node_names) {
    // 过滤出该组内存在的节点
    $group_nodes = [];
    foreach ($node_names as $node_name) {
        foreach ($all_node_data as $token_md5 => $data) {
            if ($data['name'] === $node_name) {
                $group_nodes[$token_md5] = $data;
                break;
            }
        }
    }
    
    if (empty($group_nodes)) continue;
    
    // 统计组内在线/离线数量
    $online_count = 0;
    $offline_count = 0;
    foreach ($group_nodes as $data) {
        if ($data['node'] && (time() - $data['node']['updated_at']) < 300) {
            $online_count++;
        } else {
            $offline_count++;
        }
    }
    $total_count = count($group_nodes);
    
    $group_icon = $GROUP_ICONS[$group_name] ?? 'fa-folder';
?>
                <div class="group-section">
                    <div class="group-header">
                        <i class="fas <?php echo $group_icon; ?> group-icon"></i>
                        <span class="group-name">
                            <?php echo htmlspecialchars($group_name); ?>
                            <span class="group-stats">
								<span class="stat-total"><?php echo $total_count; ?> 总数</span>
								<span class="stat-divider">|</span>
								<span class="stat-online"><?php echo $online_count; ?> 在线</span>
								<span class="stat-divider">|</span>
								<span class="stat-offline"><?php echo $offline_count; ?> 离线</span>
							</span>
                        </span>
                        <span class="group-count"><?php echo $total_count; ?> 台</span>
                    </div>
                    <div class="cards-grid" data-group="<?php echo htmlspecialchars($group_name); ?>">
<?php
    // 渲染该组内的节点卡片
    foreach ($group_nodes as $token_md5 => $data) {
        $name = $data['name'];
        $node = $data['node'];
        $traffic = $data['traffic'];
        $history_rows = $data['history'];
        
        if (!$node) {
            echo "<div class='card node-offline' data-token='{$token_md5}'>
                    <div class='node-header'>
                        <h2 class='node-title'><i class='fas fa-server'></i> <span class='node-name'>{$name}</span> <span class='flag-icon flag-icon-".get_flag_class($name)."'></span></h2>
                        <span class='status-badge offline'><i class='fas fa-circle'></i> 未初始化</span>
                    </div>
                  </div>";
            continue;
        }

        $is_online = (time() - $node['updated_at']) < 300;
        $card_class = $is_online ? "card" : "card node-offline";
        $status_text = $is_online ? "ONLINE" : "OFFLINE";
        $status_icon = $is_online ? "fa-circle online" : "fa-circle offline";
        $status_class = $is_online ? "status-badge online" : "status-badge offline";

        $cpu_cores = intval($node['cpu_cores'] ?? 0);
        $mem_total_mb = intval($node['mem_total_mb'] ?? 0);
        $disk_total_gb = intval($node['disk_total_gb'] ?? 0);
        $os_info = $node['os_info'] ?? 'Unknown';
        $uptime_seconds = intval($node['uptime_seconds'] ?? 0);

        $rx_total = $traffic['rx_total'] ?? 0;
        $tx_total = $traffic['tx_total'] ?? 0;

        $traffic_limit_gb = isset($TRAFFIC_LIMIT[$name]) ? $TRAFFIC_LIMIT[$name] : 1000;
        if ($TRAFFIC_MODE == 'both') {
            $used_traffic_bytes = $rx_total + $tx_total;
        } elseif ($TRAFFIC_MODE == 'rx') {
            $used_traffic_bytes = $rx_total;
        } else {
            $used_traffic_bytes = $tx_total;
        }
        $traffic_percent = ($traffic_limit_gb > 0) ? min(100, round(($used_traffic_bytes / ($traffic_limit_gb * 1073741824)) * 100, 1)) : 0;

        $tags = [];
        if (isset($CUSTOM_TAGS[$name]) && is_array($CUSTOM_TAGS[$name])) {
            $tags = $CUSTOM_TAGS[$name];
        }
        if (!empty($EXPIRE_DATES[$name])) {
            $remaining = days_until($EXPIRE_DATES[$name]);
            if (!empty($remaining)) $tags[] = $remaining;
        }
        if (isset($OTHER_TAGS[$name]) && is_array($OTHER_TAGS[$name])) {
            $tags = array_merge($tags, $OTHER_TAGS[$name]);
        }
?>
                        <div class="<?php echo $card_class; ?>" id="card-<?php echo $token_md5; ?>" data-token="<?php echo $token_md5; ?>" data-group="<?php echo htmlspecialchars($group_name); ?>">
                            <div class="node-header">
                                <h2 class="node-title">
                                    <i class="fas fa-server"></i> <span class="node-name"><?php echo htmlspecialchars($node['name']); ?></span>
                                    <span class="flag-icon flag-icon-<?php echo get_flag_class($name); ?>"></span>
                                </h2>
                                <span class="<?php echo $status_class; ?> status-indicator">
                                    <i class="fas <?php echo $status_icon; ?>"></i> <span class="status-text"><?php echo $status_text; ?></span>
                                </span>
                            </div>

                            <?php if ($is_online): ?>
                            <div class="card-body">
                                <div class="progress-item">
                                    <div class="progress-label"><i class="fas fa-microchip"></i> CPU</div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar cpu" style="width:<?php echo $node['cpu']; ?>%"></div>
                                        <div class="progress-text cpu-value"><?php echo $node['cpu']; ?>%</div>
                                    </div>
                                    <div class="progress-total"><?php echo $cpu_cores; ?>C</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-label"><i class="fas fa-memory"></i> RAM</div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar mem" style="width:<?php echo $node['mem']; ?>%"></div>
                                        <div class="progress-text mem-value"><?php echo $node['mem']; ?>%</div>
                                    </div>
                                    <div class="progress-total"><?php echo format_mem($mem_total_mb); ?></div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-label"><i class="fas fa-hdd"></i> Disk</div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar disk" style="width:<?php echo $node['disk']; ?>%"></div>
                                        <div class="progress-text disk-value"><?php echo $node['disk']; ?>%</div>
                                    </div>
                                    <div class="progress-total"><?php echo $disk_total_gb; ?>GB</div>
                                </div>
                                <div class="progress-item">
                                    <div class="progress-label"><i class="fas fa-chart-bar"></i> 流量</div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar traffic" style="width:<?php echo $traffic_percent; ?>%"></div>
                                        <div class="progress-text traffic-value"><?php echo format_bytes($used_traffic_bytes); ?></div>
                                    </div>
                                    <div class="progress-total"><?php echo $traffic_limit_gb; ?>GB</div>
                                </div>
<!--
                                <?php if (!empty($history_rows)): ?>
                                <div class="ping-chart-box">
                                    <canvas id="ping_hist_<?php echo $token_md5; ?>"></canvas>
                                </div>
                                <?php endif; ?>
-->
                                <div class="info-row">
                                    <div class="info-item os-item">
                                        <span class="icon"><i class="fas fa-laptop"></i></span>
                                        <span class="os-text"><?php echo short_os($os_info); ?></span>
                                    </div>
                                    <div class="info-item uptime-item">
                                        <span class="icon"><i class="fas fa-clock"></i></span>
                                        <span class="uptime-text"><?php echo format_uptime_short($uptime_seconds); ?></span>
                                    </div>
                                    <div class="info-item traffic-item">
                                        <span class="rx"><i class="fas fa-download"></i> <?php echo format_bytes($rx_total, 0); ?></span>
                                        <span class="tx">| <i class="fas fa-upload"></i> <?php echo format_bytes($tx_total, 0); ?></span>
                                    </div>
                                </div>

                                <?php if (!empty($tags)): ?>
                                <div class="tags-row">
                                    <?php foreach ($tags as $tag): ?>
                                        <?php
                                            $tag_class = 'tag';
                                            if (strpos($tag, '剩') === 0 || strpos($tag, '今日到期') !== false || strpos($tag, '已过期') !== false) {
                                                $tag_class = 'tag expire';
                                            }
                                        ?>
                                        <span class="<?php echo $tag_class; ?>"><?php echo htmlspecialchars($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="offline-placeholder">
                                <i class="fas fa-exclamation-triangle fa-2x" style="color:#ef4444;"></i>
                                <p>节点已离线，暂无实时数据</p>
                                <div class="info-row" style="margin-top:12px;">
                                    <div class="info-item os-item">
                                        <span class="icon"><i class="fas fa-laptop"></i></span>
                                        <span class="os-text"><?php echo short_os($os_info); ?></span>
                                    </div>
                                    <div class="info-item uptime-item">
                                        <span class="icon"><i class="fas fa-clock"></i></span>
                                        <span class="uptime-text"><?php echo format_uptime_short($uptime_seconds); ?></span>
                                    </div>
                                    <div class="info-item traffic-item">
                                        <span class="rx"><i class="fas fa-download"></i> <?php echo format_bytes($rx_total, 0); ?></span>
                                        <span class="tx">| <i class="fas fa-upload"></i> <?php echo format_bytes($tx_total, 0); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($tags)): ?>
                                <div class="tags-row" style="margin-top:12px;">
                                    <?php foreach ($tags as $tag): ?>
                                        <?php $tag_class = (strpos($tag, '剩') === 0 || strpos($tag, '今日到期') !== false || strpos($tag, '已过期') !== false) ? 'tag expire' : 'tag'; ?>
                                        <span class="<?php echo $tag_class; ?>"><?php echo htmlspecialchars($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
<?php } ?>
                    </div>
                </div>
<?php } ?>
            </div>
        </div>
    </div>
    
    <!-- ====== Footer ====== -->
    <footer>
        <span>Copyright @ 2005-2026 <a href="https://free163.com/" target="_blank">听松阁</a> All Rights Reserved. Power By Github</span>
    </footer>
</div>

<script>
<?php echo implode("\n", $history_scripts); ?>
</script>

<script>
// ============ 工具函数 ============
function formatBytes(bytes, precision = 2) {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const pow = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, pow)).toFixed(precision) + ' ' + units[Math.min(pow, units.length - 1)];
}
function formatMem(mb) {
    if (mb >= 1024) return (mb/1024).toFixed(1) + 'GB';
    return mb + 'MB';
}
function sampleData(arr, maxPoints = 200) {
    if (arr.length <= maxPoints) return arr;
    const step = Math.ceil(arr.length / maxPoints);
    const result = [];
    for (let i = 0; i < arr.length; i += step) result.push(arr[i]);
    return result;
}
function shortOs(full) {
    if (full.includes('Ubuntu')) return 'Ubuntu';
    if (full.includes('Debian')) return 'Debian';
    return full;
}
function formatUptimeShort(seconds) {
    const days = Math.floor(seconds / 86400);
    if (days >= 1) return days + '天';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return hours + '小时 ' + minutes + '分钟';
}
function formatTrafficInt(bytes) {
    const b = Math.round(bytes);
    if (b === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const pow = Math.floor(Math.log(b) / Math.log(1024));
    const val = (b / Math.pow(1024, pow)).toFixed(0);
    return val + ' ' + units[Math.min(pow, units.length - 1)];
}

// ============ 视图切换 ============
let currentView = 'grid';

function setView(view) {
    currentView = view;
    document.getElementById('view-grid').classList.toggle('active', view === 'grid');
    document.getElementById('view-list').classList.toggle('active', view === 'list');
    
    const container = document.getElementById('cards-container');
    const grids = container.querySelectorAll('.cards-grid');
    grids.forEach(grid => {
        grid.classList.toggle('list-view', view === 'list');
    });
    
    localStorage.setItem('probe_view', view);
}

// ============ 初始化 Ping 历史图表 ============
function initPingCharts() {
    document.querySelectorAll('.card[data-token]').forEach(card => {
        const token = card.dataset.token;
        const rawHistory = window['hist_' + token] || [];
        if (rawHistory.length === 0) return;
        const sampled = sampleData(rawHistory, 200);

        const timestamps = sampled.map(p => p.t);
        const minTime = Math.min(...timestamps);
        const maxTime = Math.max(...timestamps);

        const datasets = [
            {
                label: '移动',
                data: sampled.map(p => ({ x: p.t, y: p.ping_mobile < 0 ? null : p.ping_mobile })),
                borderColor: '#a78bfa',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: false
            },
            {
                label: '联通',
                data: sampled.map(p => ({ x: p.t, y: p.ping_unicom < 0 ? null : p.ping_unicom })),
                borderColor: '#f472b6',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: false
            },
            {
                label: '电信',
                data: sampled.map(p => ({ x: p.t, y: p.ping_telecom < 0 ? null : p.ping_telecom })),
                borderColor: '#38bdf8',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: false
            },
            {
                label: 'ATT',
                data: sampled.map(p => ({ x: p.t, y: p.ping_att < 0 ? null : p.ping_att })),
                borderColor: '#fb923c',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: false
            }
        ];

        const canvas = document.getElementById('ping_hist_' + token);
        if (!canvas) return;

        new Chart(canvas, {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        min: minTime,
                        max: maxTime,
                        ticks: {
                            color: '#9ca3af',
                            maxTicksLimit: 6,
                            callback: function(value) {
                                const d = new Date(value * 1000);
                                return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                            }
                        },
                        grid: { display: false }
                    },
                    y: {
                        min: 0,
                        ticks: {
                            color: '#9ca3af',
                            callback: v => v + ' ms'
                        },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#d1d5db', boxWidth: 10 } },
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                if (items.length > 0) {
                                    const ts = items[0].raw.x;
                                    const d = new Date(ts * 1000);
                                    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                                }
                                return '';
                            },
                            label: function(ctx) {
                                if (ctx.raw.y === null) return ctx.dataset.label + ': 超时';
                                return ctx.dataset.label + ': ' + ctx.raw.y + ' ms';
                            }
                        }
                    }
                }
            }
        });
    });
}

// ============ 自动刷新实时数据 ============
async function updateRealtimeData() {
    try {
        const res = await fetch('?ajax=realtime');
        const data = await res.json();

        document.getElementById('current-time').textContent = data.current_time;
        document.getElementById('rx-value').textContent = formatBytes(data.total_rx);
        document.getElementById('tx-value').textContent = formatBytes(data.total_tx);

        // 更新顶部统计
        if (data.stats) {
            document.getElementById('stat-total').textContent = data.stats.total;
            document.getElementById('stat-online').textContent = data.stats.online;
            document.getElementById('stat-offline').textContent = data.stats.offline;
        }

        // 更新组统计
        const groupStats = {};

        data.nodes.forEach(node => {
            const token = node.token_md5;
            const card = document.getElementById('card-' + token);
            if (!card) return;

            const groupName = card.dataset.group || '';

            if (node.online) {
                card.classList.remove('node-offline');
                card.style.borderLeftColor = '#22c55e';
                groupStats[groupName] = groupStats[groupName] || { online: 0, offline: 0, total: 0 };
                groupStats[groupName].online++;
            } else {
                card.classList.add('node-offline');
                card.style.borderLeftColor = '#ef4444';
                groupStats[groupName] = groupStats[groupName] || { online: 0, offline: 0, total: 0 };
                groupStats[groupName].offline++;
            }
            groupStats[groupName].total++;

            const statusIndicator = card.querySelector('.status-indicator');
            if (statusIndicator) {
                const icon = statusIndicator.querySelector('i');
                const text = statusIndicator.querySelector('.status-text');
                if (node.online) {
                    statusIndicator.className = 'status-badge online';
                    icon.className = 'fas fa-circle online';
                    text.textContent = 'ONLINE';
                } else {
                    statusIndicator.className = 'status-badge offline';
                    icon.className = 'fas fa-circle offline';
                    text.textContent = 'OFFLINE';
                }
            }

            // 更新进度条
            const cpuBar = card.querySelector('.progress-bar.cpu');
            const memBar = card.querySelector('.progress-bar.mem');
            const diskBar = card.querySelector('.progress-bar.disk');
            const cpuText = card.querySelector('.cpu-value');
            const memText = card.querySelector('.mem-value');
            const diskText = card.querySelector('.disk-value');
            if (cpuBar) cpuBar.style.width = node.cpu + '%';
            if (memBar) memBar.style.width = node.mem + '%';
            if (diskBar) diskBar.style.width = node.disk + '%';
            if (cpuText) cpuText.textContent = node.cpu + '%';
            if (memText) memText.textContent = node.mem + '%';
            if (diskText) diskText.textContent = node.disk + '%';

            const totals = card.querySelectorAll('.progress-total');
            if (totals[0]) totals[0].textContent = node.cpu_cores + 'C';
            if (totals[1]) totals[1].textContent = formatMem(node.mem_total_mb);
            if (totals[2]) totals[2].textContent = node.disk_total_gb + 'GB';

            const trafficBar = card.querySelector('.progress-bar.traffic');
            const trafficText = card.querySelector('.traffic-value');
            const trafficTotalEl = card.querySelectorAll('.progress-total')[3];
            if (trafficBar && trafficText && trafficTotalEl) {
                const limitGB = parseInt(trafficTotalEl.textContent) || 1000;
                const mode = '<?php echo $TRAFFIC_MODE; ?>';
                let usedBytes = 0;
                if (mode === 'both') {
                    usedBytes = (node.rx_total || 0) + (node.tx_total || 0);
                } else if (mode === 'rx') {
                    usedBytes = node.rx_total || 0;
                } else {
                    usedBytes = node.tx_total || 0;
                }
                const percent = limitGB > 0 ? Math.min(100, (usedBytes / (limitGB * 1073741824)) * 100).toFixed(1) : 0;
                trafficBar.style.width = percent + '%';
                trafficText.textContent = formatBytes(usedBytes);
            }

            const osEl = card.querySelector('.os-text');
            if (osEl) osEl.textContent = shortOs(node.os_info);
            const uptimeEl = card.querySelector('.uptime-text');
            if (uptimeEl) uptimeEl.textContent = formatUptimeShort(node.uptime_seconds);
            const rxEl = card.querySelector('.traffic-item .rx');
            const txEl = card.querySelector('.traffic-item .tx');
            if (rxEl) rxEl.innerHTML = `<i class="fas fa-download"></i> ${formatTrafficInt(node.rx_total)}`;
            if (txEl) txEl.innerHTML = `| <i class="fas fa-upload"></i> ${formatTrafficInt(node.tx_total)}`;
        });

        // 更新组头部统计 - 显示在名称后面
        document.querySelectorAll('.group-section').forEach(section => {
            const header = section.querySelector('.group-header');
            const groupName = header.querySelector('.group-name')?.textContent?.trim() || '';
            // 从groupName中提取原始名称（去掉统计数字部分）
            const cleanName = groupName.replace(/\s*\d+\s*\|\s*\d+\s*\|\s*\d+$/, '').trim();
            const stats = groupStats[cleanName] || { total: 0, online: 0, offline: 0 };
            
            // 更新group-name中的统计信息
            const nameSpan = header.querySelector('.group-name');
            if (nameSpan) {
                const statsSpan = nameSpan.querySelector('.group-stats');
                if (statsSpan) {
                    statsSpan.innerHTML = `
						<span class="stat-total">${stats.total} 总数</span>
						<span class="stat-divider">|</span>
						<span class="stat-online">${stats.online} 在线</span>
						<span class="stat-divider">|</span>
						<span class="stat-offline">${stats.offline} 离线</span>
`					;
                }
            }
            
            // 更新group-count
            const countSpan = header.querySelector('.group-count');
            if (countSpan) {
                countSpan.textContent = stats.total + ' 台';
            }
        });
    } catch (err) {
        console.error('自动刷新失败:', err);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // 恢复视图设置
    const savedView = localStorage.getItem('probe_view') || 'grid';
    setView(savedView);
    
    initPingCharts();
    setInterval(updateRealtimeData, 30000);
});
</script>

</body>
</html>