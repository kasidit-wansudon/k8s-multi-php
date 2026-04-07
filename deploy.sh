#!/bin/bash
# ═══════════════════════════════════════════════════════
#  OWAY K8s — Deploy ทั้งหมดด้วยคำสั่งเดียว
#  รองรับทั้ง Docker Desktop (Mac) และ K3s (Linux)
#  สั่ง: ./deploy.sh
# ═══════════════════════════════════════════════════════

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🚀 OWAY K8s — Starting full deploy..."
echo ""

# ─────────────────────────────────────────────────────
# STEP 0: ตรวจจับ runtime — Docker Desktop หรือ K3s
# ─────────────────────────────────────────────────────
echo "🔍 Detecting K8s runtime..."

RUNTIME=""  # "desktop" or "k3s"

# ลอง K3s ก่อน (Linux server)
if command -v k3s &>/dev/null; then
  RUNTIME="k3s"
  echo "   ✅ K3s detected"
  # ตรวจว่า K3s service ทำงานอยู่
  if ! systemctl is-active --quiet k3s 2>/dev/null; then
    echo "   ⚠️  K3s service ไม่ทำงาน — กำลังเริ่ม..."
    sudo systemctl start k3s
    sleep 10
  fi
  # ตั้ง KUBECONFIG ให้ kubectl ใช้ได้
  export KUBECONFIG=/etc/rancher/k3s/k3s.yaml
  # ถ้า kubectl ไม่มี ใช้ k3s kubectl แทน
  if ! command -v kubectl &>/dev/null; then
    alias kubectl='k3s kubectl'
    KUBECTL="k3s kubectl"
  else
    KUBECTL="kubectl"
  fi
else
  # ลอง Docker Desktop K8s (Mac/Windows)
  NODES=$(docker ps --format "{{.Names}}" | grep -E "^desktop-(control-plane|worker)" || true)
  if [ -n "$NODES" ]; then
    RUNTIME="desktop"
    KUBECTL="kubectl"
    echo "   ✅ Docker Desktop K8s detected"
    echo "   Found nodes:"
    for NODE in $NODES; do echo "   - $NODE"; done
  fi
fi

if [ -z "$RUNTIME" ]; then
  echo "❌ ไม่พบ K8s runtime — ติดตั้ง K3s ก่อน:"
  echo "   curl -sfL https://get.k3s.io | sh -"
  echo "   หรือเปิด Docker Desktop แล้ว enable Kubernetes"
  exit 1
fi

echo ""

# ─────────────────────────────────────────────────────
# STEP 1: ติดตั้ง PHP Frameworks (ถ้ายังไม่ได้ติดตั้ง)
# ─────────────────────────────────────────────────────
echo "📦 Checking PHP frameworks..."

# Laminas MVC
if [ ! -f "$SCRIPT_DIR/apps/zend2/vendor/autoload.php" ]; then
  echo "   ⏳ Installing Laminas MVC Skeleton (อาจใช้เวลาสักครู่)..."
  TMPDIR_ZEND=$(mktemp -d)
  docker run --rm --network=host \
    -v "$TMPDIR_ZEND":/app \
    -u "$(id -u):$(id -g)" \
    composer:latest \
    create-project laminas/laminas-mvc-skeleton . --no-interaction --ignore-platform-req=php
  # copy ทับ แต่เก็บไฟล์เดิม (Dockerfile ฯลฯ) ไว้
  cp -rn "$TMPDIR_ZEND"/. "$SCRIPT_DIR/apps/zend2/"
  rm -rf "$TMPDIR_ZEND"
  echo "   ✅ Laminas MVC installed"
fi

# ตรวจว่า vendor ตรงกับ composer.json (เช่น doctrine/orm) — ถ้าไม่ → composer update
if [ -f "$SCRIPT_DIR/apps/zend2/composer.json" ] && \
   grep -q '"doctrine/orm"' "$SCRIPT_DIR/apps/zend2/composer.json" && \
   [ ! -f "$SCRIPT_DIR/apps/zend2/vendor/doctrine/orm/src/ORMSetup.php" ]; then
  echo "   ⏳ Syncing Laminas vendor (composer update)..."
  docker run --rm --network=host \
    -v "$SCRIPT_DIR/apps/zend2":/app \
    -u "$(id -u):$(id -g)" \
    composer:latest \
    update --ignore-platform-req=php --no-interaction
  echo "   ✅ Laminas vendor synced"
else
  echo "   ✅ Laminas vendor — already in sync"
fi

# Laravel
if [ ! -f "$SCRIPT_DIR/apps/laravel/vendor/autoload.php" ]; then
  echo "   ⏳ Installing Laravel (อาจใช้เวลาสักครู่)..."
  TMPDIR_LARAVEL=$(mktemp -d)
  docker run --rm --network=host \
    -v "$TMPDIR_LARAVEL":/app \
    -u "$(id -u):$(id -g)" \
    composer:latest \
    create-project laravel/laravel . --no-interaction
  cp -rn "$TMPDIR_LARAVEL"/. "$SCRIPT_DIR/apps/laravel/"
  rm -rf "$TMPDIR_LARAVEL"
  echo "   ✅ Laravel installed"
else
  echo "   ✅ Laravel — already installed (skipping)"
fi

# Set permissions for Laravel
echo "   🔒 Setting Laravel permissions..."
chmod -R 777 "$SCRIPT_DIR/apps/laravel/storage" \
             "$SCRIPT_DIR/apps/laravel/bootstrap/cache" \
             "$SCRIPT_DIR/apps/laravel/database"
[ -f "$SCRIPT_DIR/apps/laravel/database/database.sqlite" ] && \
  chmod 777 "$SCRIPT_DIR/apps/laravel/database/database.sqlite"
echo "   ✅ Permissions set"
echo ""

# ─────────────────────────────────────────────────────
# STEP 2: Build custom PHP images
# ─────────────────────────────────────────────────────
echo "🐳 Building custom PHP images..."

if ! docker image inspect oway-php83:latest &>/dev/null; then
  echo "   ⏳ Building oway-php83 (PHP 8.3-FPM + Redis สำหรับ Laminas)..."
  docker build --network=host -f "$SCRIPT_DIR/docker/php83.Dockerfile" \
    -t oway-php83:latest "$SCRIPT_DIR/docker"
  echo "   ✅ oway-php83 built"
else
  echo "   ✅ oway-php83 — already built (skipping)"
fi

if ! docker image inspect oway-php84:latest &>/dev/null; then
  echo "   ⏳ Building oway-php84 (PHP 8.4-FPM + Redis สำหรับ Laravel)..."
  docker build --network=host -f "$SCRIPT_DIR/docker/php84.Dockerfile" \
    -t oway-php84:latest "$SCRIPT_DIR/docker"
  echo "   ✅ oway-php84 built"
else
  echo "   ✅ oway-php84 — already built (skipping)"
fi
echo ""

# ─────────────────────────────────────────────────────
# STEP 3: Load images เข้า K8s
# ─────────────────────────────────────────────────────
echo "📦 Loading images into K8s..."

IMAGES=(
  "oway-php83:latest"
  "oway-php84:latest"
  "phpmyadmin:latest"
  "mariadb:10.11"
  "httpd:2.4"
  "redis:7.2-alpine"
)

for IMAGE in "${IMAGES[@]}"; do
  if ! docker image inspect "$IMAGE" &>/dev/null; then
    echo "   ⬇️  Pulling $IMAGE..."
    docker pull "$IMAGE"
  fi

  if [ "$RUNTIME" = "k3s" ]; then
    # K3s containerd: import image จาก Docker
    echo "   ⏳ $IMAGE → K3s containerd"
    docker save "$IMAGE" | sudo /usr/local/bin/k3s ctr images import - &>/dev/null
    echo "   ✅ $IMAGE done"
  else
    # Docker Desktop: load เข้าทุก node
    for NODE in $NODES; do
      echo "   ⏳ $IMAGE → $NODE"
      docker save "$IMAGE" | docker exec -i "$NODE" ctr -n k8s.io images import - &>/dev/null
    done
    echo "   ✅ $IMAGE done"
  fi
done
echo ""

# ─────────────────────────────────────────────────────
# STEP 4: เตรียม app code สำหรับ hostPath
# ─────────────────────────────────────────────────────
echo "📁 Preparing app code for K8s..."

if [ "$RUNTIME" = "k3s" ]; then
  # K3s: host filesystem = K8s node filesystem โดยตรง
  # สร้าง /apps/ symlink หรือ copy ไว้ที่ /apps/
  APPS_DIR="/apps"
  sudo mkdir -p "$APPS_DIR/zend2" "$APPS_DIR/laravel"
  sudo rsync -a --delete "$SCRIPT_DIR/apps/zend2/" "$APPS_DIR/zend2/"
  sudo rsync -a --delete "$SCRIPT_DIR/apps/laravel/" "$APPS_DIR/laravel/"
  sudo chmod -R 777 "$APPS_DIR/laravel/storage" \
                     "$APPS_DIR/laravel/bootstrap/cache" \
                     "$APPS_DIR/laravel/database" || true
  echo "   ✅ App code synced to $APPS_DIR"
else
  # Docker Desktop: copy เข้า node containers
  for NODE in $NODES; do
    docker exec "$NODE" mkdir -p /apps/zend2 /apps/laravel
    docker cp "$SCRIPT_DIR/apps/zend2/."  "$NODE:/apps/zend2/"
    docker cp "$SCRIPT_DIR/apps/laravel/." "$NODE:/apps/laravel/"
    docker exec "$NODE" chmod -R 777 /apps/laravel/storage \
                                      /apps/laravel/bootstrap/cache \
                                      /apps/laravel/database || true
    echo "   ✅ $NODE — apps synced"
  done
fi
echo ""

# ─────────────────────────────────────────────────────
# STEP 5: แทนค่า hostPath จริงใน YAML ชั่วคราว
# ─────────────────────────────────────────────────────
TMPDIR_YAML=$(mktemp -d)
for f in base/*.yaml; do
  sed 's|/HOST_APPS/|/apps/|g' "$f" > "$TMPDIR_YAML/$(basename $f)"
done

# ─────────────────────────────────────────────────────
# STEP 6: TLS cert
#   Mac: mkcert (browser เชื่อถือได้)
#   Linux: openssl self-signed (สำหรับ dev/lab)
# ─────────────────────────────────────────────────────
echo "🔐 Setting up TLS certificate..."

CERT_DIR="$SCRIPT_DIR/certs"
mkdir -p "$CERT_DIR"

if [ ! -f "$CERT_DIR/oway-tls.crt" ] || [ ! -f "$CERT_DIR/oway-tls.key" ]; then
  if command -v mkcert &>/dev/null; then
    echo "   ⏳ Generating mkcert certificate..."
    mkcert -key-file "$CERT_DIR/oway-tls.key" \
           -cert-file "$CERT_DIR/oway-tls.crt" \
           zend2.localhost laravel.localhost localhost "*.localhost"
    echo "   ✅ Certificate generated (mkcert — trusted by browser)"
  else
    echo "   ⏳ Generating self-signed certificate (openssl)..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
      -keyout "$CERT_DIR/oway-tls.key" \
      -out "$CERT_DIR/oway-tls.crt" \
      -subj "/CN=localhost" \
      -addext "subjectAltName=DNS:zend2.localhost,DNS:laravel.localhost,DNS:localhost,DNS:*.localhost" \
      2>/dev/null
    echo "   ✅ Self-signed certificate generated (browser จะขึ้น warning — ปกติสำหรับ dev)"
  fi
else
  echo "   ✅ Using existing cert: $CERT_DIR/oway-tls.crt"
fi
echo ""

# ─────────────────────────────────────────────────────
# STEP 7: Deploy resources ตามลำดับ
# ─────────────────────────────────────────────────────

echo "📋 Applying Kubernetes resources..."

$KUBECTL apply -f "$TMPDIR_YAML/namespace.yaml"

$KUBECTL create secret tls oway-tls \
  --cert="$CERT_DIR/oway-tls.crt" \
  --key="$CERT_DIR/oway-tls.key" \
  -n oway --dry-run=client -o yaml | $KUBECTL apply -f -

$KUBECTL apply -f "$TMPDIR_YAML/mysql-secret.yaml"
$KUBECTL apply -f "$SCRIPT_DIR/base/app-env-configmap.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/mysql-init-configmap.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/app-code-configmap.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/apache-config.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/mysql.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/phpmyadmin.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/php74.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/php84.yaml"
$KUBECTL apply -f "$TMPDIR_YAML/apache.yaml"
$KUBECTL apply -f "$SCRIPT_DIR/base/redis.yaml"
$KUBECTL apply -f "$SCRIPT_DIR/base/k8s-rbac.yaml"
$KUBECTL apply -f "$SCRIPT_DIR/base/hpa.yaml"

rm -rf "$TMPDIR_YAML"
echo ""

# ─────────────────────────────────────────────────────
# STEP 8: รอทุก deployment พร้อม
# ─────────────────────────────────────────────────────
echo "⏳ Waiting for all pods to be ready..."
$KUBECTL wait --for=condition=available deployment --all -n oway --timeout=180s
echo ""

# ─────────────────────────────────────────────────────
# STEP 9: Port-forward (background)
#   Linux server: bind 0.0.0.0 เพื่อให้เข้าจากภายนอกได้
# ─────────────────────────────────────────────────────
echo "🔗 Starting port-forward..."
pkill -f "kubectl port-forward" 2>/dev/null || true
sleep 1

if [ "$RUNTIME" = "k3s" ]; then
  # K3s: NodePort เปิดอยู่แล้วที่ 665, 666, 667
  echo "   ✅ NodePort พร้อมใช้ (665/666/667)"
else
  $KUBECTL port-forward svc/apache     665:665  -n oway &>/dev/null &
  $KUBECTL port-forward svc/apache     666:666  -n oway &>/dev/null &
  $KUBECTL port-forward svc/phpmyadmin 667:667  -n oway &>/dev/null &
  echo "   ✅ Port-forward running in background"
fi

sleep 2
echo ""

# ─────────────────────────────────────────────────────
# STEP 9.5: Host Apache reverse proxy (K3s / Linux only)
#   ให้เข้าผ่าน port 80 แทน 665/666/667
# ─────────────────────────────────────────────────────
if [ "$RUNTIME" = "k3s" ] && [ -f "$SCRIPT_DIR/host-apache/k8s-proxy.conf" ]; then
  echo "🔀 Installing host Apache reverse proxy..."

  # หา Apache config directory (RHEL/Debian)
  APACHE_CONF_DIR=""
  if [ -d /etc/httpd/conf.d ]; then
    APACHE_CONF_DIR="/etc/httpd/conf.d"
    APACHE_SVC="httpd"
  elif [ -d /etc/apache2/conf-available ]; then
    APACHE_CONF_DIR="/etc/apache2/conf-available"
    APACHE_SVC="apache2"
  fi

  if [ -n "$APACHE_CONF_DIR" ] && systemctl is-active --quiet "$APACHE_SVC" 2>/dev/null; then
    # แทน IP ใน config ให้ตรงกับ server ปัจจุบัน
    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    TMP_CONF=$(mktemp)
    sed "s|172.31.7.223|$SERVER_IP|g" "$SCRIPT_DIR/host-apache/k8s-proxy.conf" > "$TMP_CONF"
    sudo cp "$TMP_CONF" "$APACHE_CONF_DIR/k8s-proxy.conf"
    rm -f "$TMP_CONF"

    # ถ้าเป็น Debian ต้อง enable ด้วย
    if [ "$APACHE_SVC" = "apache2" ]; then
      sudo a2enconf k8s-proxy &>/dev/null || true
    fi

    if sudo "$APACHE_SVC" -t 2>/dev/null || sudo apachectl -t 2>/dev/null; then
      sudo systemctl reload "$APACHE_SVC"
      echo "   ✅ Host Apache proxy installed → reload $APACHE_SVC"
    else
      echo "   ⚠️  Apache config syntax error — skipped reload"
    fi
  else
    echo "   ℹ️  Host Apache not running — ข้าม step นี้"
  fi
  echo ""
fi

# ─────────────────────────────────────────────────────
# STEP 10: สรุปผล
# ─────────────────────────────────────────────────────

# หา IP สำหรับแสดงผล
if [ "$RUNTIME" = "k3s" ]; then
  SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  [ -z "$SERVER_IP" ] && SERVER_IP="<your-server-ip>"
fi

echo "════════════════════════════════════════════════"
echo "  ✅ Deploy สำเร็จ!"
echo "════════════════════════════════════════════════"
echo ""
$KUBECTL get pods -n oway
echo ""

if [ "$RUNTIME" = "k3s" ]; then
  echo "  ─── เข้าผ่าน Host Apache (port 80) — แนะนำ ───"
  echo "  🌐 Laminas MVC   → http://zend2.${SERVER_IP}.nip.io"
  echo "  🚀 Laravel       → http://laravel.${SERVER_IP}.nip.io"
  echo "  📈 K8s Console   → http://laravel.${SERVER_IP}.nip.io/k8s"
  echo "  📊 phpMyAdmin    → http://pma.${SERVER_IP}.nip.io"
  echo ""
  echo "  ─── หรือเข้า NodePort ตรง ───"
  echo "  🌐 Laminas MVC   → https://$SERVER_IP:666  (Host: zend2.localhost)"
  echo "  🚀 Laravel       → https://$SERVER_IP:666  (Host: laravel.localhost)"
  echo "  📊 phpMyAdmin    → http://$SERVER_IP:667"
  echo ""
  echo "  🔒 Self-signed cert — browser จะขึ้น warning (ปกติสำหรับ dev/lab)"
else
  echo "  🌐 Laminas MVC   → https://zend2.localhost:666"
  echo "  🚀 Laravel       → https://laravel.localhost:666"
  echo "  📈 K8s Console   → https://laravel.localhost:666/k8s"
  echo "  📊 phpMyAdmin    → http://localhost:667"
  echo ""
  echo "  🔒 HTTPS พร้อมใช้ — mkcert CA ติดตั้งใน Mac แล้ว browser ไม่ขึ้น warning"
fi
echo ""
echo "  🔄 sync code ใหม่หลังแก้ไฟล์: ./sync-code.sh"
echo "  📖 Lab & Test Cases: cat lab.md"
echo "  🗑️  ลบทั้งหมด: kubectl delete namespace oway"
echo "  🔌 หยุด port-forward: pkill -f 'kubectl port-forward'"
echo ""
