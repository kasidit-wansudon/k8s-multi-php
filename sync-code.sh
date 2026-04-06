#!/bin/bash
# ═══════════════════════════════════════════════════════
#  OWAY — Sync app code เข้า K8s
#  รองรับทั้ง Docker Desktop (Mac) และ K3s (Linux)
#  ใช้หลังจากแก้ไฟล์ใน apps/zend2 หรือ apps/laravel
#  สั่ง: ./sync-code.sh
# ═══════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🔄 Syncing app code to K8s..."

# ─── ตรวจจับ runtime ─────────────────────────────────
if command -v k3s &>/dev/null; then
  RUNTIME="k3s"
  export KUBECONFIG=/etc/rancher/k3s/k3s.yaml
else
  RUNTIME="desktop"
fi

if [ "$RUNTIME" = "k3s" ]; then
  # K3s: rsync โดยตรงเข้า /apps/ บน host (= node filesystem)
  sudo rsync -a --delete "$SCRIPT_DIR/apps/zend2/"  /apps/zend2/
  sudo rsync -a --delete "$SCRIPT_DIR/apps/laravel/" /apps/laravel/
  sudo chmod -R 777 /apps/laravel/storage \
                     /apps/laravel/bootstrap/cache \
                     /apps/laravel/database || true
  echo "   ✅ K3s — apps synced to /apps/"

  # Restart PHP pods เพื่อ reload code (OPcache)
  echo "   🔄 Rolling restart PHP pods..."
  KUBECTL="kubectl"
  command -v kubectl &>/dev/null || KUBECTL="k3s kubectl"
  $KUBECTL rollout restart deployment/php84 -n oway 2>/dev/null || true
  $KUBECTL rollout restart deployment/php74 -n oway 2>/dev/null || true
  echo "   ✅ PHP pods restarting"
else
  # Docker Desktop: docker cp เข้า node containers
  NODES=$(docker ps --format "{{.Names}}" | grep -E "^desktop-(control-plane|worker)" || true)

  if [ -z "$NODES" ]; then
    echo "❌ ไม่พบ K8s nodes"
    exit 1
  fi

  for NODE in $NODES; do
    docker cp "$SCRIPT_DIR/apps/zend2/."  "$NODE:/apps/zend2/"
    docker cp "$SCRIPT_DIR/apps/laravel/." "$NODE:/apps/laravel/"
    echo "   ✅ $NODE synced"
  done
fi

echo ""
echo "✅ Done! รีเฟรช browser ได้เลย"

if [ "$RUNTIME" = "k3s" ]; then
  SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  echo "   https://$SERVER_IP:666  (Host: zend2.localhost / laravel.localhost)"
else
  echo "   https://zend2.localhost:666"
  echo "   https://laravel.localhost:666"
fi
