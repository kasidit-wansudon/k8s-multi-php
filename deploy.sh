#!/bin/bash
# ═══════════════════════════════════════════════════════
#  OWAY K8s — Deploy ทั้งหมดด้วยคำสั่งเดียว
#  สั่ง: ./deploy.sh
# ═══════════════════════════════════════════════════════

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🚀 OWAY K8s — Starting full deploy..."
echo ""

# ─────────────────────────────────────────────────────
# STEP 1: หา K8s nodes จาก Docker อัตโนมัติ
# ─────────────────────────────────────────────────────
echo "🔍 Detecting K8s nodes..."
NODES=$(docker ps --format "{{.Names}}" | grep -E "^desktop-(control-plane|worker)" || true)

if [ -z "$NODES" ]; then
  echo "❌ ไม่พบ K8s nodes — ตรวจสอบว่า Docker Desktop เปิดอยู่และ K8s enabled"
  exit 1
fi

echo "   Found nodes:"
for NODE in $NODES; do echo "   - $NODE"; done
echo ""

# ─────────────────────────────────────────────────────
# STEP 2: ติดตั้ง PHP Frameworks (ถ้ายังไม่ได้ติดตั้ง)
# ─────────────────────────────────────────────────────
echo "📦 Checking PHP frameworks..."

# Laminas MVC
if [ ! -f "$SCRIPT_DIR/apps/zend2/vendor/autoload.php" ]; then
  echo "   ⏳ Installing Laminas MVC Skeleton (อาจใช้เวลาสักครู่)..."
  docker run --rm \
    -v "$SCRIPT_DIR/apps/zend2":/app \
    -u "$(id -u):$(id -g)" \
    composer:latest \
    create-project laminas/laminas-mvc-skeleton . --no-interaction --ignore-platform-req=php
  echo "   ✅ Laminas MVC installed"
else
  echo "   ✅ Laminas MVC — already installed (skipping)"
fi

# Laravel
if [ ! -f "$SCRIPT_DIR/apps/laravel/vendor/autoload.php" ]; then
  echo "   ⏳ Installing Laravel (อาจใช้เวลาสักครู่)..."
  docker run --rm \
    -v "$SCRIPT_DIR/apps/laravel":/app \
    -u "$(id -u):$(id -g)" \
    composer:latest \
    create-project laravel/laravel . --no-interaction
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
# STEP 3: Build custom PHP images (ถ้ายังไม่มี)
# ─────────────────────────────────────────────────────
echo "🐳 Building custom PHP images..."

if ! docker image inspect oway-php83:latest &>/dev/null; then
  echo "   ⏳ Building oway-php83 (PHP 8.3-FPM + Redis สำหรับ Laminas)..."
  docker build -f "$SCRIPT_DIR/docker/php83.Dockerfile" \
    -t oway-php83:latest "$SCRIPT_DIR/docker"
  echo "   ✅ oway-php83 built"
else
  echo "   ✅ oway-php83 — already built (skipping)"
fi

if ! docker image inspect oway-php84:latest &>/dev/null; then
  echo "   ⏳ Building oway-php84 (PHP 8.4-FPM + Redis สำหรับ Laravel)..."
  docker build -f "$SCRIPT_DIR/docker/php84.Dockerfile" \
    -t oway-php84:latest "$SCRIPT_DIR/docker"
  echo "   ✅ oway-php84 built"
else
  echo "   ✅ oway-php84 — already built (skipping)"
fi
echo ""

# ─────────────────────────────────────────────────────
# STEP 4: Load images เข้าทุก node
# ─────────────────────────────────────────────────────
echo "📦 Loading images into K8s nodes..."

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
    echo "   ⏬ Pulling $IMAGE..."
    docker pull "$IMAGE"
  fi
  for NODE in $NODES; do
    echo "   ⏳ $IMAGE → $NODE"
    docker save "$IMAGE" | docker exec -i "$NODE" ctr -n k8s.io images import - &>/dev/null
  done
  echo "   ✅ $IMAGE done"
done
echo ""

# ─────────────────────────────────────────────────────
# STEP 5: Copy โฟลเดอร์ apps เข้าทุก node
#   hostPath ใน K8s kind ต้องอยู่ใน node container ไม่ใช่ Mac
# ─────────────────────────────────────────────────────
echo "📁 Syncing app code into K8s nodes..."

ZEND2_SRC="$SCRIPT_DIR/apps/zend2"
LARAVEL_SRC="$SCRIPT_DIR/apps/laravel"

for NODE in $NODES; do
  docker exec "$NODE" mkdir -p /apps/zend2 /apps/laravel
  docker cp "$ZEND2_SRC/."  "$NODE:/apps/zend2/"
  docker cp "$LARAVEL_SRC/." "$NODE:/apps/laravel/"
  # fix permissions inside node
  docker exec "$NODE" chmod -R 777 /apps/laravel/storage \
                                    /apps/laravel/bootstrap/cache \
                                    /apps/laravel/database || true
  echo "   ✅ $NODE — apps synced"
done
echo ""

# ─────────────────────────────────────────────────────
# STEP 6: แทนค่า hostPath จริงใน YAML ชั่วคราว
# ─────────────────────────────────────────────────────
TMPDIR_YAML=$(mktemp -d)
for f in base/*.yaml; do
  sed 's|/HOST_APPS/|/apps/|g' "$f" > "$TMPDIR_YAML/$(basename $f)"
done

# ─────────────────────────────────────────────────────
# STEP 7: TLS cert จาก mkcert (browser เชื่อถือได้ ไม่ขึ้น warning)
# ─────────────────────────────────────────────────────
echo "🔐 Setting up TLS certificate (mkcert)..."

CERT_DIR="$SCRIPT_DIR/certs"

if [ ! -f "$CERT_DIR/oway-tls.crt" ] || [ ! -f "$CERT_DIR/oway-tls.key" ]; then
  if ! command -v mkcert &>/dev/null; then
    echo "   ❌ ไม่พบ mkcert — ติดตั้งก่อนด้วย: brew install mkcert && mkcert -install"
    exit 1
  fi
  echo "   ⏳ Generating mkcert certificate..."
  mkdir -p "$CERT_DIR"
  mkcert -key-file "$CERT_DIR/oway-tls.key" \
         -cert-file "$CERT_DIR/oway-tls.crt" \
         zend2.localhost laravel.localhost localhost
  echo "   ✅ Certificate generated (valid until 2028)"
else
  echo "   ✅ Using existing cert: $CERT_DIR/oway-tls.crt"
fi

echo ""

# ─────────────────────────────────────────────────────
# STEP 8: Deploy resources ตามลำดับ
# ─────────────────────────────────────────────────────
echo "📋 Applying Kubernetes resources..."

kubectl apply -f "$TMPDIR_YAML/namespace.yaml"

kubectl create secret tls oway-tls \
  --cert="$CERT_DIR/oway-tls.crt" \
  --key="$CERT_DIR/oway-tls.key" \
  -n oway --dry-run=client -o yaml | kubectl apply -f -

kubectl apply -f "$TMPDIR_YAML/mysql-secret.yaml"
kubectl apply -f "$SCRIPT_DIR/base/app-env-configmap.yaml"
kubectl apply -f "$TMPDIR_YAML/mysql-init-configmap.yaml"
kubectl apply -f "$TMPDIR_YAML/app-code-configmap.yaml"
kubectl apply -f "$TMPDIR_YAML/apache-config.yaml"
kubectl apply -f "$TMPDIR_YAML/mysql.yaml"
kubectl apply -f "$TMPDIR_YAML/phpmyadmin.yaml"
kubectl apply -f "$TMPDIR_YAML/php74.yaml"
kubectl apply -f "$TMPDIR_YAML/php84.yaml"
kubectl apply -f "$TMPDIR_YAML/apache.yaml"
kubectl apply -f "$SCRIPT_DIR/base/redis.yaml"
kubectl apply -f "$SCRIPT_DIR/base/k8s-rbac.yaml"
kubectl apply -f "$SCRIPT_DIR/base/hpa.yaml"

rm -rf "$TMPDIR_YAML"
echo ""

# ─────────────────────────────────────────────────────
# STEP 9: รอทุก deployment พร้อม
# ─────────────────────────────────────────────────────
echo "⏳ Waiting for all pods to be ready..."
kubectl wait --for=condition=available deployment --all -n oway --timeout=180s
echo ""

# ─────────────────────────────────────────────────────
# STEP 10: Port-forward (background)
# ─────────────────────────────────────────────────────
echo "🔗 Starting port-forward..."
pkill -f "kubectl port-forward" 2>/dev/null || true
sleep 1

kubectl port-forward svc/apache     30080:80  -n oway &>/dev/null &
kubectl port-forward svc/apache     30443:443 -n oway &>/dev/null &
kubectl port-forward svc/phpmyadmin 9888:80   -n oway &>/dev/null &

sleep 2
echo "   ✅ Port-forward running in background"
echo ""

# ─────────────────────────────────────────────────────
# STEP 11: สรุปผล
# ─────────────────────────────────────────────────────
echo "════════════════════════════════════════════════"
echo "  ✅ Deploy สำเร็จ!"
echo "════════════════════════════════════════════════"
echo ""
kubectl get pods -n oway
echo ""
echo "  🌐 Laminas MVC   → https://zend2.localhost:30443"
echo "  🚀 Laravel       → https://laravel.localhost:30443"
echo "  📈 K8s Console   → https://laravel.localhost:30443/k8s"
echo "  📊 phpMyAdmin    → http://localhost:9888"
echo ""
echo "  🔒 HTTPS พร้อมใช้ — mkcert CA ติดตั้งใน Mac แล้ว browser ไม่ขึ้น warning"
echo ""
echo "  🔄 sync code ใหม่หลังแก้ไฟล์: ./sync-code.sh"
echo "  📖 Lab & Test Cases: cat lab.md"
echo "  🗑️  ลบทั้งหมด: kubectl delete namespace oway"
echo "  🔌 หยุด port-forward: pkill -f 'kubectl port-forward'"
echo ""
