#!/bin/bash
set -e

# ==========================================
# 休闲探针 - 客户端安装脚本
# 版本：1.0
# 用法：以 root 运行：./install.sh <你的TOKEN>
# 示例：./install.sh abc123def456
# ==========================================

# ---------- 参数检查 ----------
TOKEN="${1}"
if [[ -z "$TOKEN" ]]; then
    echo "用法: $0 <TOKEN>"
    echo "示例: $0 你的token原文"
    exit 1
fi

if [[ $EUID -ne 0 ]]; then
    echo "请以 root 用户运行此脚本。"
    exit 1
fi

# ---------- 配置变量（可根据需要修改） ----------
APP_USER="hyruo"                     # 运行探针的低权限用户
INSTALL_DIR="/opt/hyruo"            # 安装目录
SERVICE_NAME="hyruo"                # systemd 服务名
API_URL="https://vps.hhhnn.com/report.php"  # 接收端的 URL（请修改！）
INTERVAL=120                         # 上报间隔（秒）

echo "===== Hyruo 探针安装 ====="
echo "Token: $TOKEN"

# 检查必需命令
for cmd in curl awk free df ping vmstat; do
    if ! command -v $cmd >/dev/null 2>&1; then
        echo "缺少命令: $cmd，请先安装 (例如 apt install procps curl ...)"
        exit 1
    fi
done

# 创建低权限系统用户
if id "$APP_USER" &>/dev/null; then
    echo "用户 $APP_USER 已存在，跳过创建。"
else
    useradd -r -s /usr/sbin/nologin -M "$APP_USER"
    echo "用户 $APP_USER 已创建。"
fi

# 创建安装目录
mkdir -p "$INSTALL_DIR"

# 写入探针脚本（不包含 token，token 通过环境变量注入）
cat > "$INSTALL_DIR/client.sh" <<'CLIENTEOF'
#!/bin/bash
API="__API_URL__"
TOKEN="${TOKEN:-}"               # 由 systemd 环境变量注入
INTERVAL=__INTERVAL__

if [[ -z "$TOKEN" ]]; then
    echo "错误：未设置 TOKEN 环境变量，请检查 systemd 配置。"
    exit 1
fi

get_ping() {
    local target=$1
    # 发2个包，超时1秒，取平均值
    local result=$(ping -c 2 -W 1 "$target" 2>/dev/null \
        | grep -oE "(time|时间)=[0-9.]+" \
        | tr -dc '0-9.\n' \
        | awk '{sum+=$1; cnt++} END { if(cnt>0) printf "%.0f", sum/cnt; else print -1 }')
    echo "${result:--1}"
}

# 总量数据（仅采集一次）
CPU_CORES=$(nproc)
MEM_TOTAL_MB=$(free -m | awk 'NR==2{print $2}')
DISK_TOTAL_GB=$(df -BG / | awk 'NR==2{print $2}' | sed 's/G//')
if [ -f /etc/os-release ]; then
    OS_INFO=$(grep '^PRETTY_NAME=' /etc/os-release | cut -d= -f2- | tr -d '"')
else
    OS_INFO="Unknown"
fi

echo "探针已启动！CPU核心: $CPU_CORES, 内存: ${MEM_TOTAL_MB}MB, 磁盘: ${DISK_TOTAL_GB}GB, OS: $OS_INFO"
echo "正在持续上报数据至 $API ..."

while true; do
    CPU=$(vmstat 1 2 | tail -1 | awk '{print 100 - $15}')
    [[ -z "$CPU" ]] && CPU=0

    MEM=$(free -m | awk 'NR==2{printf "%.2f", $3*100/$2 }')
    DISK=$(df -h / | awk '$NF=="/"{print $5}' | sed 's/%//g')

    RX=$(awk 'NR>2 && $1 !~ "lo:" {rx+=$2} END {print rx}' /proc/net/dev)
    TX=$(awk 'NR>2 && $1 !~ "lo:" {tx+=$10} END {print tx}' /proc/net/dev)

    PING_MOBILE=$(get_ping "120.196.165.24")
    PING_UNICOM=$(get_ping "210.21.196.6")
    PING_TELECOM=$(get_ping "183.47.102.225")
    PING_ATT=$(get_ping "223.5.5.5")

    UPTIME_SEC=$(awk '{print int($1)}' /proc/uptime)

    DATA="{\"token\":\"$TOKEN\",\"cpu\":${CPU:-0},\"mem\":${MEM:-0},\"disk\":${DISK:-0},\"rx\":${RX:-0},\"tx\":${TX:-0},\"ping_mobile\":${PING_MOBILE:--1},\"ping_unicom\":${PING_UNICOM:--1},\"ping_telecom\":${PING_TELECOM:--1},\"ping_att\":${PING_ATT:--1},\"cpu_cores\":$CPU_CORES,\"mem_total_mb\":$MEM_TOTAL_MB,\"disk_total_gb\":$DISK_TOTAL_GB,\"os_info\":\"$OS_INFO\",\"uptime_seconds\":$UPTIME_SEC}"

    curl -s -X POST "$API" \
        -H "Content-Type: application/json" \
        -d "$DATA" > /dev/null

    echo "[$(date +'%H:%M:%S')] 成功上报一次 -> CPU: ${CPU}% | ATT延迟: ${PING_ATT}ms"
    sleep $INTERVAL
done
CLIENTEOF

# 替换占位符
sed -i "s|__API_URL__|$API_URL|g" "$INSTALL_DIR/client.sh"
sed -i "s|__INTERVAL__|$INTERVAL|g" "$INSTALL_DIR/client.sh"

# 安全权限：脚本归 root，其他人只读，不包含 token
chmod 755 "$INSTALL_DIR/client.sh"
chown root:root "$INSTALL_DIR/client.sh"

# 创建环境变量文件存放 token，仅 root 可读
cat > "$INSTALL_DIR/env" <<EOF
TOKEN=$TOKEN
EOF
chmod 600 "$INSTALL_DIR/env"
chown root:root "$INSTALL_DIR/env"

# 创建 systemd 服务
cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<SERVICE
[Unit]
Description=Hyruo Status Client
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$APP_USER
Group=$APP_USER
EnvironmentFile=$INSTALL_DIR/env
ExecStart=$INSTALL_DIR/client.sh
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"

echo "===== 安装完成 ====="
echo "查看服务状态: systemctl status $SERVICE_NAME"
echo "查看日志: journalctl -u $SERVICE_NAME -f"