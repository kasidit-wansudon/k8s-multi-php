#!/bin/bash
# ═══════════════════════════════════════════════════════
#  K3s Installation Script สำหรับ Amazon Linux / RHEL
#  รันบน EC2 instance (x86_64)
#  สั่ง: sudo ./setup-k3s.sh
#
#  Port layout:
#  - K3s API           : 6443 (default, internal only)
#  - Container Apache  : 665 (HTTP), 666 (HTTPS)
#  - phpMyAdmin        : 667
#  - Host Apache เดิม  : 80, 443 (ไม่ยุ่ง)
# ═══════════════════════════════════════════════════════

set -e

echo "═══════════════════════════════════════════════════"
echo "  K3s Setup สำหรับ OWAY K8s Multi-PHP Lab"
echo "═══════════════════════════════════════════════════"
echo ""

# ─── ตรวจว่ารันด้วย root ────────────────────────────
if [ "$EUID" -ne 0 ]; then
  echo "❌ กรุณารันด้วย sudo: sudo ./setup-k3s.sh"
  exit 1
fi

# ─── 1. ติดตั้ง dependencies ────────────────────────
echo "📦 Installing dependencies..."

if command -v dnf &>/dev/null; then
  PKG_MGR="dnf"
elif command -v yum &>/dev/null; then
  PKG_MGR="yum"
else
  PKG_MGR="apt-get"
fi

$PKG_MGR install -y curl rsync bc openssl 2>/dev/null || true

# ติดตั้ง Docker (ถ้ายังไม่มี)
if ! command -v docker &>/dev/null; then
  echo "🐳 Installing Docker..."
  if [ "$PKG_MGR" = "apt-get" ]; then
    curl -fsSL https://get.docker.com | sh
  else
    $PKG_MGR install -y docker
  fi
  systemctl enable docker
  systemctl start docker
  REAL_USER="${SUDO_USER:-$USER}"
  usermod -aG docker "$REAL_USER" 2>/dev/null || true
  echo "   ✅ Docker installed"
else
  echo "   ✅ Docker already installed"
fi

echo ""

# ─── 2. ติดตั้ง K3s ────────────────────────────────
echo "☸️  Installing K3s..."

if [ -x /usr/local/bin/k3s ]; then
  echo "   ⚠️  พบ K3s ตัวเก่าติดตั้งอยู่"
  echo "   👉 กรุณา uninstall ก่อนแล้วรัน script นี้ใหม่:"
  echo ""
  echo "      sudo /usr/local/bin/k3s-uninstall.sh"
  echo "      sudo ./setup-k3s.sh"
  echo ""
  exit 1
fi

# ติดตั้ง K3s — ใช้ default ทั้งหมด (เสถียรที่สุด)
# --disable=traefik: ไม่ต้องใช้ traefik (เราใช้ Apache container เอง)
# --write-kubeconfig-mode=644: ให้ user ทั่วไปอ่าน kubeconfig ได้
# --service-node-port-range=660-670: เปิด NodePort ต่ำ (665/666/667)
curl -sfL https://get.k3s.io | INSTALL_K3S_EXEC="--disable=traefik --write-kubeconfig-mode=644 --service-node-port-range=660-670" sh -

echo "   ⏳ Waiting for K3s to be ready..."
for i in $(seq 1 30); do
  if /usr/local/bin/k3s kubectl get nodes 2>/dev/null | grep -q "Ready"; then
    echo "   ✅ K3s installed and running"
    break
  fi
  sleep 2
done

echo ""

# ─── 3. ตั้งค่า kubectl ─────────────────────────────
echo "🔧 Configuring kubectl..."

if ! command -v kubectl &>/dev/null; then
  ln -sf /usr/local/bin/k3s /usr/local/bin/kubectl 2>/dev/null || true
fi

REAL_USER="${SUDO_USER:-$USER}"
REAL_HOME=$(eval echo "~$REAL_USER")

mkdir -p "$REAL_HOME/.kube"
cp /etc/rancher/k3s/k3s.yaml "$REAL_HOME/.kube/config"
chown "$REAL_USER:$(id -g "$REAL_USER")" "$REAL_HOME/.kube/config"
chmod 600 "$REAL_HOME/.kube/config"

BASHRC="$REAL_HOME/.bashrc"
if ! grep -q "KUBECONFIG" "$BASHRC" 2>/dev/null; then
  echo "" >> "$BASHRC"
  echo "# K3s kubectl config" >> "$BASHRC"
  echo 'export KUBECONFIG=/etc/rancher/k3s/k3s.yaml' >> "$BASHRC"
fi

echo "   ✅ kubectl configured"
echo ""

# ─── 4. สร้างโฟลเดอร์ /apps/ ──────────────────────
echo "📁 Creating /apps/ directory..."
mkdir -p /apps/zend2 /apps/laravel
chmod 777 /apps /apps/zend2 /apps/laravel
echo "   ✅ /apps/ ready"
echo ""

# ─── 5. ตรวจสอบ ────────────────────────────────────
echo "🔍 Verifying setup..."
echo ""

echo "   Node status:"
/usr/local/bin/k3s kubectl get nodes
echo ""

echo "   System pods:"
/usr/local/bin/k3s kubectl get pods -n kube-system
echo ""

METRICS=$( /usr/local/bin/k3s kubectl get pods -n kube-system 2>/dev/null | grep metrics-server | wc -l)
if [ "$METRICS" -ge 1 ]; then
  echo "   ✅ metrics-server is running (HPA จะทำงานได้)"
else
  echo "   ⚠️  metrics-server not found — HPA อาจไม่ทำงาน"
fi

echo ""
echo "═══════════════════════════════════════════════════"
echo "  ✅ Setup เสร็จสมบูรณ์!"
echo "═══════════════════════════════════════════════════"
echo ""
echo "  K3s API         : 6443 (internal)"
echo "  Apache HTTP     : 665 (NodePort)"
echo "  Apache HTTPS    : 666 (NodePort)"
echo "  phpMyAdmin      : 667 (NodePort)"
echo ""
echo "  ขั้นตอนถัดไป:"
echo "  1. export KUBECONFIG=/etc/rancher/k3s/k3s.yaml"
echo "  2. ./deploy.sh"
echo ""
echo "  ❗ ถ้าต้องการ uninstall K3s:"
echo "  - /usr/local/bin/k3s-uninstall.sh"
echo ""
