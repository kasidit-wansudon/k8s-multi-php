#!/bin/bash
# ═══════════════════════════════════════════════════════
#  K3s Installation Script สำหรับ Amazon Linux / RHEL
#  รันบน EC2 instance (x86_64)
#  สั่ง: sudo ./setup-k3s.sh
# ═══════════════════════════════════════════════════════

set -e

# ─── ตั้งค่า Port ─────────────────────────────────────
K3S_PORT="${K3S_PORT:-661}"

echo "═══════════════════════════════════════════════════"
echo "  K3s Setup สำหรับ OWAY K8s Multi-PHP Lab"
echo "  Port: $K3S_PORT"
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
  # เพิ่ม user ปัจจุบันเข้า docker group
  REAL_USER="${SUDO_USER:-$USER}"
  usermod -aG docker "$REAL_USER" 2>/dev/null || true
  echo "   ✅ Docker installed"
else
  echo "   ✅ Docker already installed"
fi

echo ""

# ─── 2. ติดตั้ง K3s ────────────────────────────────
echo "☸️  Installing K3s (port: $K3S_PORT)..."

if command -v k3s &>/dev/null; then
  echo "   ✅ K3s already installed ($(k3s --version | head -1))"

  # ตรวจสอบว่า K3s มี --service-node-port-range และ --https-listen-port ถูกต้องหรือไม่
  K3S_SERVICE_FILE="/etc/systemd/system/k3s.service"
  NEED_RESTART=false

  if [ -f "$K3S_SERVICE_FILE" ]; then
    if ! grep -q "service-node-port-range=660-670" "$K3S_SERVICE_FILE"; then
      echo "   ⚙️  เพิ่ม --service-node-port-range=660-670 ใน K3s config..."
      sed -i "s|server|server --service-node-port-range=660-670|" "$K3S_SERVICE_FILE"
      NEED_RESTART=true
    fi
    if ! grep -q "https-listen-port=$K3S_PORT" "$K3S_SERVICE_FILE"; then
      echo "   ⚙️  เพิ่ม --https-listen-port=$K3S_PORT ใน K3s config..."
      sed -i "s|server|server --https-listen-port=$K3S_PORT|" "$K3S_SERVICE_FILE"
      NEED_RESTART=true
    fi
    if [ "$NEED_RESTART" = true ]; then
      echo "   🔄 Restarting K3s..."
      systemctl daemon-reload
      systemctl restart k3s
      sleep 10
      echo "   ✅ K3s restarted with new config"
    else
      echo "   ✅ K3s config already correct"
    fi
  fi
else
  # ติดตั้ง K3s พร้อม Docker backend
  # --docker: ใช้ Docker แทน containerd (เพราะเราใช้ docker build อยู่แล้ว)
  # --disable=traefik: ไม่ต้องใช้ traefik (เราใช้ Apache เอง)
  # --write-kubeconfig-mode=644: ให้ user ทั่วไปอ่าน kubeconfig ได้
  curl -sfL https://get.k3s.io | INSTALL_K3S_EXEC="--docker --disable=traefik --write-kubeconfig-mode=644 --https-listen-port=$K3S_PORT --service-node-port-range=660-670" sh -

  echo "   ⏳ Waiting for K3s to be ready..."
  sleep 10

  # รอจนกว่า K3s node จะ Ready
  for i in $(seq 1 30); do
    if k3s kubectl get nodes 2>/dev/null | grep -q "Ready"; then
      break
    fi
    sleep 2
  done

  echo "   ✅ K3s installed and running"
fi

echo ""

# ─── 3. ตั้งค่า kubectl ─────────────────────────────
echo "🔧 Configuring kubectl..."

# สร้าง symlink ให้ kubectl ใช้ได้ตรงๆ
if ! command -v kubectl &>/dev/null; then
  ln -sf /usr/local/bin/k3s /usr/local/bin/kubectl 2>/dev/null || true
fi

# ตั้ง KUBECONFIG ให้ user ทั่วไป
REAL_USER="${SUDO_USER:-$USER}"
REAL_HOME=$(eval echo "~$REAL_USER")

mkdir -p "$REAL_HOME/.kube"
cp /etc/rancher/k3s/k3s.yaml "$REAL_HOME/.kube/config"
# ปรับ port ใน kubeconfig ให้ตรงกับที่กำหนด
sed -i "s|https://127.0.0.1:6443|https://127.0.0.1:$K3S_PORT|g" "$REAL_HOME/.kube/config"
chown "$REAL_USER:$(id -g "$REAL_USER")" "$REAL_HOME/.kube/config"
chmod 600 "$REAL_HOME/.kube/config"

# เพิ่ม KUBECONFIG ใน bashrc ถ้ายังไม่มี
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
k3s kubectl get nodes
echo ""

echo "   System pods:"
k3s kubectl get pods -n kube-system
echo ""

# ตรวจ metrics-server (K3s มีในตัว)
METRICS=$( k3s kubectl get pods -n kube-system 2>/dev/null | grep metrics-server | wc -l)
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
echo "  K3s API Port: $K3S_PORT"
echo ""
echo "  ขั้นตอนถัดไป:"
echo "  1. logout แล้ว login ใหม่ (เพื่อให้ docker group มีผล)"
echo "  2. export KUBECONFIG=/etc/rancher/k3s/k3s.yaml"
echo "  3. ./deploy.sh"
echo ""
echo "  คำสั่งที่มีประโยชน์:"
echo "  - kubectl get nodes        # ดู node status"
echo "  - kubectl get pods -A      # ดู pods ทั้งหมด"
echo "  - systemctl status k3s     # ดู K3s service status"
echo "  - k3s kubectl top nodes    # ดู resource usage"
echo ""
echo "  ❗ ถ้าต้องการ uninstall K3s:"
echo "  - /usr/local/bin/k3s-uninstall.sh"
echo ""
