#!/bin/bash
# ═══════════════════════════════════════════════════════
#  OWAY — Sync app code เข้า K8s nodes
#  ใช้หลังจากแก้ไฟล์ใน apps/zend2 หรือ apps/laravel
#  สั่ง: ./sync-code.sh
# ═══════════════════════════════════════════════════════

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🔄 Syncing app code to K8s nodes..."

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

echo ""
echo "✅ Done! รีเฟรช browser ได้เลย"
echo "   https://zend2.localhost:30443"
echo "   https://laravel.localhost:30443"
