<?php
/*
 * ==========================================
 * 管理后台 - admin.php
 * 版本：3.2
 * 说明：服务器探针管理后台，用于管理服务器配置
 *       配置存储在 config.json 和 report.json 中
 * ==========================================
 */

session_start();

// ---------- 数据库路径 ----------
$DB_FILE = __DIR__ . "/data/status_v2_xxxxxxxxxxxxxxxxxxxx.db";
if (!is_dir(__DIR__ . "/data")) {
    mkdir(__DIR__ . "/data", 0755, true);
}
$db = new SQLite3($DB_FILE);

// ---------- 管理员配置表 ----------
$db->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at INTEGER DEFAULT 0
)");

// ---------- 初始化默认管理员账号（密码：admin123） ----------
$default_password = 'admin123';
$default_hash = md5($default_password);
$check_stmt = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'");
$check_res = $check_stmt->execute();
$count = $check_res->fetchArray()[0];

if ($count == 0) {
    $db->exec("INSERT INTO admin_users (username, password_hash, created_at) VALUES ('admin', '$default_hash', " . time() . ")");
}

// ---------- 登录处理 ----------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $login_error = '请输入用户名和密码！';
    } else {
        $stmt = $db->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($res && md5($password) === $res['password_hash']) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $res['username'];
            $_SESSION['admin_id'] = $res['id'];
            header('Location: admin.php');
            exit;
        } else {
            $login_error = '用户名或密码错误！';
        }
    }
}

// ---------- 登出处理 ----------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---------- 检查登录状态 ----------
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// ---------- 修改密码 ----------
if (isset($_POST['action']) && $_POST['action'] === 'change_password' && is_logged_in()) {
    $old_password = trim($_POST['old_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $pwd_error = '请填写所有字段！';
    } elseif ($new_password !== $confirm_password) {
        $pwd_error = '两次输入的新密码不一致！';
    } elseif (strlen($new_password) < 6) {
        $pwd_error = '新密码长度至少6位！';
    } else {
        $stmt = $db->prepare("SELECT password_hash FROM admin_users WHERE id = :id");
        $stmt->bindValue(':id', $_SESSION['admin_id'], SQLITE3_INTEGER);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($res && md5($old_password) === $res['password_hash']) {
            $new_hash = md5($new_password);
            $update = $db->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id");
            $update->bindValue(':hash', $new_hash, SQLITE3_TEXT);
            $update->bindValue(':id', $_SESSION['admin_id'], SQLITE3_INTEGER);
            $update->execute();
            $_SESSION['pwd_success'] = '密码修改成功！';
            header('Location: admin.php');
            exit;
        } else {
            $pwd_error = '原密码错误！';
        }
    }
}

// ---------- 生成随机 Token ----------
function generate_token($length = 32) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $token;
}

// ============ JSON 配置文件操作函数 ============

// 加载 config.json
function load_config_json() {
    $file = __DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json';
    if (!file_exists($file)) {
        return ['servers' => []];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return $data ?: ['servers' => []];
}

// 保存 config.json
function save_config_json($data) {
    $file = __DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 加载 report.json
function load_report_json() {
    $file = __DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json';
    if (!file_exists($file)) {
        return ['servers' => []];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return $data ?: ['servers' => []];
}

// 保存 report.json
function save_report_json($data) {
    $file = __DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 获取所有分组列表（从 config.json 读取）
function get_all_groups_from_config() {
    $config = load_config_json();
    $groups = [];
    foreach ($config['servers'] as $server) {
        $group = $server['group'] ?? '未分组';
        if (!in_array($group, $groups)) {
            $groups[] = $group;
        }
    }
    return $groups;
}

// 获取分组图标映射
function get_group_icons() {
    $config = load_config_json();
    $icons = [];
    foreach ($config['servers'] as $server) {
        $group = $server['group'] ?? '未分组';
        $icon = $server['group_icon'] ?? 'fa-folder';
        if (!isset($icons[$group])) {
            $icons[$group] = $icon;
        }
    }
    return $icons;
}

// ---------- 添加服务器 ----------
if (isset($_POST['action']) && $_POST['action'] === 'add_server' && is_logged_in()) {
    // 生成一个唯一标识防止重复提交
    $form_token = $_POST['form_token'] ?? '';
    $session_token = $_SESSION['add_server_token'] ?? '';
    
    // 验证token防止重复提交
    if (empty($form_token) || $form_token !== $session_token) {
        $_SESSION['add_error'] = '表单已过期，请重新提交！';
        header('Location: admin.php');
        exit;
    }
    
    // 清除session token，防止重复使用
    unset($_SESSION['add_server_token']);
    
    $sort = intval($_POST['sort'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $group = trim($_POST['group'] ?? '');
    $traffic = intval($_POST['traffic'] ?? 1000);
    $custom_tag = trim($_POST['custom_tag'] ?? '');
    $expire_date = trim($_POST['expire_date'] ?? '');
    $other_tag = trim($_POST['other_tag'] ?? '');
    $flag = trim($_POST['flag'] ?? '');
    
    if (empty($name)) {
        $_SESSION['add_error'] = '服务器名称不能为空！';
        header('Location: admin.php');
        exit;
    }
    
    if (empty($group)) {
        $group = '未分组';
    }
    
    // 检查服务器名称是否已存在
    $config = load_config_json();
    foreach ($config['servers'] as $server) {
        if ($server['name'] === $name) {
            $_SESSION['add_error'] = '服务器名称 "' . $name . '" 已存在！';
            header('Location: admin.php');
            exit;
        }
    }
    
    // 如果排序为空或0，自动计算最大排序值+1
    if ($sort <= 0) {
        $max_sort = 0;
        foreach ($config['servers'] as $server) {
            $s = $server['sort'] ?? 0;
            if ($s > $max_sort) {
                $max_sort = $s;
            }
        }
        $sort = $max_sort + 1;
    }
    
    // 生成 Token
    $token_original = generate_token(32);
    $token_md5 = md5($token_original);
    
    // 备份配置文件
    $backup_dir = __DIR__ . '/backup';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // 备份 config.json
    if (file_exists(__DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json')) {
        copy(__DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json', $backup_dir . '/config_' . date('Ymd_His') . '.json');
    }
    
    // 备份 report.json
    if (file_exists(__DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json')) {
        copy(__DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json', $backup_dir . '/report_' . date('Ymd_His') . '.json');
    }
    
    // ---- 添加到 config.json ----
    $config = load_config_json();
    
    // 构建自定义标签数组
    $custom_tags = [];
    if (!empty($custom_tag)) {
        $custom_tags = [$custom_tag];
    }
    
    // 构建其他标签数组
    $other_tags = [];
    if (!empty($other_tag)) {
        $other_tags = [$other_tag];
    }
    
    $new_server = [
        'name' => $name,
        'token_md5' => $token_md5,
        'group' => $group,
        'group_icon' => 'fa-globe-asia',
        'traffic_limit' => $traffic,
        'custom_tags' => $custom_tags,
        'expire_date' => $expire_date,
        'other_tags' => $other_tags,
        'flag' => $flag,
        'sort' => $sort
    ];
    
    $config['servers'][] = $new_server;
    save_config_json($config);
    
    // ---- 添加到 report.json ----
    $report = load_report_json();
    $new_report_server = [
        'token_original' => $token_original,
        'name' => $name
    ];
    $report['servers'][] = $new_report_server;
    save_report_json($report);
    
    $_SESSION['add_success'] = '服务器添加成功！Token原文：' . $token_original;
    header('Location: admin.php');
    exit;
}

// ---------- 删除服务器 ----------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && is_logged_in()) {
    $name = trim($_GET['name'] ?? '');
    if (!empty($name)) {
        // 备份配置文件
        $backup_dir = __DIR__ . '/backup';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        if (file_exists(__DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json')) {
            copy(__DIR__ . '/data/config_xxxxxxxxxxxxxxxxxxxx.json', $backup_dir . '/config_' . date('Ymd_His') . '.json');
        }
        if (file_exists(__DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json')) {
            copy(__DIR__ . '/data/report_xxxxxxxxxxxxxxxxxxxx.json', $backup_dir . '/report_' . date('Ymd_His') . '.json');
        }
        
        // ---- 从 config.json 删除 ----
        $config = load_config_json();
        $new_servers = [];
        foreach ($config['servers'] as $server) {
            if ($server['name'] !== $name) {
                $new_servers[] = $server;
            }
        }
        $config['servers'] = $new_servers;
        save_config_json($config);
        
        // ---- 从 report.json 删除 ----
        $report = load_report_json();
        $new_report_servers = [];
        foreach ($report['servers'] as $server) {
            if ($server['name'] !== $name) {
                $new_report_servers[] = $server;
            }
        }
        $report['servers'] = $new_report_servers;
        save_report_json($report);
        
        $_SESSION['delete_success'] = '服务器 "' . $name . '" 已删除！';
        header('Location: admin.php');
        exit;
    }
}

// ---------- 获取Session消息 ----------
$add_success = $_SESSION['add_success'] ?? '';
$add_error = $_SESSION['add_error'] ?? '';
$delete_success = $_SESSION['delete_success'] ?? '';
$pwd_success = $_SESSION['pwd_success'] ?? '';
unset($_SESSION['add_success'], $_SESSION['add_error'], $_SESSION['delete_success'], $_SESSION['pwd_success']);

// ---------- 生成表单token ----------
$_SESSION['add_server_token'] = bin2hex(random_bytes(32));
$form_token = $_SESSION['add_server_token'];

// ---------- 获取当前配置 ----------
$config = load_config_json();
$report = load_report_json();

// 构建 token_original 映射（按名称查找）
$token_original_map = [];
foreach ($report['servers'] as $rserver) {
    $token_original_map[$rserver['name']] = $rserver['token_original'] ?? '';
}

$servers = $config['servers'] ?? [];
$all_groups = get_all_groups_from_config();
$group_icons = get_group_icons();

// 构建服务器列表
$server_list = [];
foreach ($servers as $server) {
    $name = $server['name'] ?? '';
    $server_list[] = [
        'name' => $name,
        'token_md5' => $server['token_md5'] ?? '',
        'token_original' => $token_original_map[$name] ?? '',
        'group' => $server['group'] ?? '未分组',
        'traffic' => $server['traffic_limit'] ?? 1000,
        'custom_tags' => $server['custom_tags'] ?? [],
        'expire_date' => $server['expire_date'] ?? '',
        'other_tags' => $server['other_tags'] ?? [],
        'sort' => $server['sort'] ?? 0
    ];
}

// 按 sort 字段正序排序
usort($server_list, function($a, $b) {
    return ($a['sort'] ?? 0) - ($b['sort'] ?? 0);
});

// ---------- 页面开始 ----------
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 听松阁探针</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0f172a;
            color: #e2e8f0;
            font-family: 'Segoe UI', Arial, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* ====== 美化登录页面 ====== */
        .login-page {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            padding: 20px;
        }
        .login-box {
            width: 100%;
            max-width: 420px;
            background: #1e293b;
            padding: 50px 40px 40px;
            border-radius: 20px;
            border: 1px solid rgba(34, 197, 94, 0.2);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }
        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #22c55e, #16a34a, #22c55e);
            background-size: 200% 100%;
            animation: gradientMove 3s ease infinite;
        }
        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .login-box .login-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 25px;
            font-size: 30px;
            color: #fff;
            box-shadow: 0 8px 30px rgba(34, 197, 94, 0.3);
        }
        .login-box h2 {
            text-align: center;
            font-size: 24px;
            color: #fff;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .login-box .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .login-box .form-group {
            margin-bottom: 20px;
        }
        .login-box .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            color: #94a3b8;
            font-weight: 500;
        }
        .login-box .form-group .input-wrap {
            position: relative;
        }
        .login-box .form-group .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
        }
        .login-box .form-group input {
            width: 100%;
            padding: 12px 14px 12px 44px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            color: #e2e8f0;
            font-size: 14px;
            transition: all 0.3s;
        }
        .login-box .form-group input:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
        }
        .login-box .form-group input::placeholder {
            color: #475569;
        }
        .login-box .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
        .login-box .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(34, 197, 94, 0.3);
        }
        .login-box .btn-login:active {
            transform: translateY(0);
        }
        .login-box .default-info {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #334155;
            font-size: 13px;
            color: #64748b;
        }
        .login-box .default-info strong {
            color: #94a3b8;
        }
        .login-box .alert {
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success {
            background: rgba(6, 95, 70, 0.3);
            color: #6ee7b7;
            border: 1px solid #047857;
        }
        .alert-error {
            background: rgba(127, 29, 29, 0.3);
            color: #fca5a5;
            border: 1px solid #b91c1c;
        }
        .alert-info {
            background: rgba(30, 58, 95, 0.3);
            color: #93c5fd;
            border: 1px solid #1e40af;
        }
        
        /* ====== 主界面样式 ====== */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: #1e293b;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid #22c55e;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header h1 i { color: #22c55e; }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .header-actions .user-info {
            color: #94a3b8;
            font-size: 14px;
        }
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #22c55e; color: #fff; }
        .btn-primary:hover { background: #16a34a; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-secondary { background: #334155; color: #e2e8f0; }
        .btn-secondary:hover { background: #475569; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        .card {
            background: #1e293b;
            border-radius: 12px;
            padding: 25px 30px;
            border: 1px solid #334155;
            margin-bottom: 25px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #334155;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header h2 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header h2 i { color: #22c55e; }
        .card-header .badge {
            background: #334155;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            color: #94a3b8;
        }
        
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        table th {
            text-align: left;
            padding: 10px 12px;
            background: #0f172a;
            color: #94a3b8;
            font-weight: 600;
            border-bottom: 2px solid #334155;
            white-space: nowrap;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #1e293b;
            vertical-align: middle;
        }
        table tr:hover td { background: #0f172a; }
        table .tag {
            display: inline-block;
            background: #334155;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: #94a3b8;
            margin: 2px;
        }
        table .tag-expire {
            background: #7f1d1d;
            color: #fca5a5;
        }
        table .code {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: #0f172a;
            padding: 4px 8px;
            border-radius: 4px;
            color: #60a5fa;
        }
        .text-muted { color: #64748b; }
        
        /* 模态框 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal {
            background: #1e293b;
            border-radius: 16px;
            padding: 35px 40px;
            max-width: 550px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid #334155;
            position: relative;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #334155;
        }
        .modal-header h2 {
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-header h2 i { color: #22c55e; }
        .modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s;
        }
        .modal-close:hover { color: #fff; }
        .modal .form-group {
            margin-bottom: 16px;
        }
        .modal .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #94a3b8;
        }
        .modal .form-group input,
        .modal .form-group select {
            width: 100%;
            padding: 10px 14px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #e2e8f0;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .modal .form-group input:focus,
        .modal .form-group select:focus {
            outline: none;
            border-color: #22c55e;
        }
        .modal .form-group .help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
        .modal .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #334155;
        }
        
        .copy-btn {
            background: #0f172a;
            border: 1px solid #334155;
            color: #94a3b8;
            padding: 2px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s;
        }
        .copy-btn:hover {
            background: #22c55e;
            color: #fff;
            border-color: #22c55e;
        }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; text-align: center; }
            .header-actions { justify-content: center; }
            .modal { padding: 25px 20px; }
            table { font-size: 12px; }
            table th, table td { padding: 6px 8px; }
            .login-box { padding: 30px 20px; }
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body>

<div class="container">
    <?php if (!is_logged_in()): ?>
        <!-- ====== 登录页面 ====== -->
        <div class="login-page">
            <div class="login-box">
                <div class="login-icon">
                    <i class="fas fa-server"></i>
                </div>
                <h2>管理后台</h2>
                <p class="subtitle">听松阁探针 · 服务器管理</p>
                
                <?php if (isset($login_error)): ?>
                    <div class="alert alert-error"><?php echo $login_error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label><i class="fas fa-user" style="margin-right:6px;"></i> 用户名</label>
                        <div class="input-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text" name="username" placeholder="请输入用户名" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock" style="margin-right:6px;"></i> 密码</label>
                        <div class="input-wrap">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" placeholder="请输入密码" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> 登录
                    </button>
                </form>
                <div class="default-info">
                    默认账号 <strong>admin</strong> / 密码 <strong>admin123</strong>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- ====== 管理后台 ====== -->
        <div class="header">
            <h1><i class="fas fa-server"></i> 服务器管理</h1>
            <div class="header-actions">
                <span class="user-info"><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['admin_user']); ?></span>
                <a href="index.php" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> 查看探针</a>
                <button onclick="openModal()" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> 添加服务器</button>
                <button onclick="openPasswordModal()" class="btn btn-warning btn-sm"><i class="fas fa-key"></i> 修改密码</button>
                <a href="?action=logout" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> 退出</a>
            </div>
        </div>
        
        <?php if ($add_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $add_success; ?>
                <br><small>请记录此Token原文，客户端配置需要使用。</small>
            </div>
        <?php endif; ?>
        <?php if ($add_error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $add_error; ?></div>
        <?php endif; ?>
        <?php if ($delete_success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $delete_success; ?></div>
        <?php endif; ?>
        <?php if ($pwd_success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $pwd_success; ?></div>
        <?php endif; ?>
        
        <!-- ====== 服务器列表 ====== -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> 服务器列表</h2>
                <span class="badge"><?php echo count($server_list); ?> 台</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;">排序</th>
                            <th>服务器名称</th>
                            <th>分组</th>
                            <th>流量(GB)</th>
                            <th>自定义标签</th>
                            <th>到期时间</th>
                            <th>其他标签</th>
                            <th>安装命令</th>
                            <th style="width:60px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($server_list as $server): ?>
                        <tr>
                            <td><?php echo $server['sort'] ?? 0; ?></td>
                            <td><strong><?php echo htmlspecialchars($server['name']); ?></strong></td>
                            <td>
                                <span class="tag"><?php echo htmlspecialchars($server['group'] ?: '未分组'); ?></span>
                            </td>
                            <td><?php echo $server['traffic']; ?></td>
                            <td>
                                <?php foreach ($server['custom_tags'] as $tag): ?>
                                    <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if (!empty($server['expire_date'])): ?>
                                    <span class="tag <?php echo (strpos($server['expire_date'], '免费') !== false || strpos($server['expire_date'], '月付') !== false || strpos($server['expire_date'], '季付') !== false) ? '' : 'tag-expire'; ?>">
                                        <?php echo htmlspecialchars($server['expire_date']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($server['other_tags'] as $tag): ?>
                                    <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <code class="code">./install.sh <?php echo htmlspecialchars($server['token_original']); ?></code>
                                <button class="copy-btn" onclick="copyText('./install.sh <?php echo htmlspecialchars($server['token_original']); ?>')">复制</button>
                            </td>
                            <td>
                                <a href="?action=delete&name=<?php echo urlencode($server['name']); ?>" 
                                   class="btn btn-danger btn-sm" 
                                   onclick="return confirm('确定要删除服务器 "<?php echo htmlspecialchars($server['name']); ?>" 吗？')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($server_list)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:30px; color:#64748b;">
                                <i class="fas fa-inbox" style="font-size:24px; display:block; margin-bottom:10px;"></i>
                                暂无服务器，点击右上角 "添加服务器" 按钮添加
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- ====== 添加服务器模态框 ====== -->
        <div class="modal-overlay" id="addModal">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-plus-circle"></i> 添加服务器</h2>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <form method="POST" onsubmit="return validateForm()">
                    <input type="hidden" name="action" value="add_server">
                    <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                    
                    <div class="form-group">
                        <label>排序 <span style="color:#ef4444;">*</span></label>
                        <input type="number" name="sort" id="sort" value="<?php echo count($server_list) + 1; ?>" min="1" required>
                        <div class="help-text">显示顺序，数字越小越靠前</div>
                    </div>
                    
                    <div class="form-group">
                        <label>服务器名称 <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="name" id="name" placeholder="例如：Oracle ARM Singapore" required>
                    </div>
                    
                    <div class="form-group">
                        <label>分组 <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="group" id="group" placeholder="例如：海外节点" list="group-list" required>
                        <datalist id="group-list">
                            <?php foreach ($all_groups as $group): ?>
                                <option value="<?php echo htmlspecialchars($group); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="help-text">输入已有分组名称或创建新分组</div>
                    </div>
                    
                    <div class="form-group">
                        <label>国家代码 (flag) <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="flag" id="flag" placeholder="例如：sg, us, jp, cn" value="us" required>
                        <div class="help-text">用于显示国旗图标，如：sg(新加坡), us(美国), jp(日本), cn(中国), kr(韩国), hk(香港), fr(法国), de(德国), br(巴西)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>流量配额 (GB) <span style="color:#ef4444;">*</span></label>
                        <input type="number" name="traffic" id="traffic" value="1000" min="0" required>
                        <div class="help-text">每月流量限制，单位 GB</div>
                    </div>
                    
                    <div class="form-group">
                        <label>自定义标签</label>
                        <input type="text" name="custom_tag" id="custom_tag" placeholder="例如：🇸🇬 新加坡">
                        <div class="help-text">显示在服务器卡片上的标签</div>
                    </div>
                    
                    <div class="form-group">
                        <label>到期时间</label>
                        <input type="text" name="expire_date" id="expire_date" placeholder="YYYY-MM-DD 或 免费、月付、季付">
                        <div class="help-text">格式：2025-12-31 或 免费、月付、季付</div>
                    </div>
                    
                    <div class="form-group">
                        <label>其他标签</label>
                        <input type="text" name="other_tag" id="other_tag" placeholder="例如：$5/mo">
                        <div class="help-text">价格、带宽等其他信息</div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 添加服务器</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- ====== 修改密码模态框 ====== -->
        <div class="modal-overlay" id="passwordModal">
            <div class="modal">
                <div class="modal-header">
                    <h2><i class="fas fa-key"></i> 修改密码</h2>
                    <button class="modal-close" onclick="closePasswordModal()">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label>原密码 <span style="color:#ef4444;">*</span></label>
                        <input type="password" name="old_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label>新密码 <span style="color:#ef4444;">*</span></label>
                        <input type="password" name="new_password" minlength="6" required>
                        <div class="help-text">至少6位</div>
                    </div>
                    
                    <div class="form-group">
                        <label>确认新密码 <span style="color:#ef4444;">*</span></label>
                        <input type="password" name="confirm_password" required>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 修改密码</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// ============ 模态框控制 ============
function openModal() {
    document.getElementById('addModal').classList.add('active');
}

function closeModal() {
    document.getElementById('addModal').classList.remove('active');
}

function openPasswordModal() {
    document.getElementById('passwordModal').classList.add('active');
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.remove('active');
}

// 点击外部关闭模态框
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
    }
});

// ============ 表单验证 ============
function validateForm() {
    const name = document.getElementById('name').value.trim();
    const group = document.getElementById('group').value.trim();
    const flag = document.getElementById('flag').value.trim();
    
    if (!name) {
        alert('请输入服务器名称！');
        return false;
    }
    if (!group) {
        alert('请输入分组名称！');
        return false;
    }
    if (!flag) {
        alert('请输入国家代码！');
        return false;
    }
    if (flag.length !== 2) {
        alert('国家代码必须为2位字母！例如：sg, us, jp, cn');
        return false;
    }
    return true;
}

// ============ 复制文本 ============
function copyText(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            alert('已复制到剪贴板！');
        }, function() {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    alert('已复制到剪贴板！');
}
</script>

</body>
</html>