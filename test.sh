#!/bin/bash
# ═══════════════════════════════════════════════════════
#  OWAY K8s Test Suite — รันทุก test case อัตโนมัติ
#  Usage: ./test.sh
# ═══════════════════════════════════════════════════════

PASS=0
FAIL=0
SKIP=0

LARAVEL="https://laravel.localhost:30443"
LAMINAS="https://zend2.localhost:30443"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

pass() { echo -e "  ${GREEN}✓ PASS${NC} $1"; ((PASS++)); }
fail() { echo -e "  ${RED}✗ FAIL${NC} $1"; ((FAIL++)); }
skip() { echo -e "  ${YELLOW}⊘ SKIP${NC} $1"; ((SKIP++)); }
section() { echo -e "\n${BOLD}${CYAN}━━━ $1 ━━━${NC}"; }

# ── Helper: get value from page ──
get_total_ms()  { curl -sk "$1" | grep -o 'val g">[0-9.]*' | grep -o '[0-9.]*' | head -1; }
get_db_ms()     { curl -sk "$1" | grep -o 'val y">[0-9.]*' | grep -o '[0-9.]*' | head -1; }
get_cached_count() { curl -sk "$1" | grep -o '⚡ cached' | wc -l | tr -d ' '; }
get_hostname()  { curl -sk "$1" | grep -o 'val r">[^<]*' | sed 's/val r">//'; }
http_status()   { curl -sk -o /dev/null -w "%{http_code}" "$1"; }

echo ""
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  OWAY K8s Test Suite${NC}"
echo -e "${BOLD}════════════════════════════════════════════════${NC}"

# ══════════════════════════════════════════════════════
section "1. Connectivity"
# ══════════════════════════════════════════════════════

STATUS=$(http_status "$LARAVEL")
[ "$STATUS" = "200" ] && pass "Laravel responds HTTP 200" || fail "Laravel HTTP $STATUS (expected 200)"

STATUS=$(http_status "$LAMINAS")
[ "$STATUS" = "200" ] && pass "Laminas responds HTTP 200" || fail "Laminas HTTP $STATUS (expected 200)"

STATUS=$(http_status "$LARAVEL/k8s")
[ "$STATUS" = "200" ] && pass "K8s Console responds HTTP 200" || fail "K8s Console HTTP $STATUS"

STATUS=$(http_status "$LARAVEL/k8s/api")
[ "$STATUS" = "200" ] && pass "K8s API responds HTTP 200" || fail "K8s API HTTP $STATUS"

# ══════════════════════════════════════════════════════
section "2. Pod Health"
# ══════════════════════════════════════════════════════

NOT_RUNNING=$(kubectl get pods -n oway --no-headers 2>/dev/null | grep -v "Running\|Completed" | wc -l | tr -d ' ')
[ "$NOT_RUNNING" = "0" ] && pass "All pods are Running" || fail "$NOT_RUNNING pod(s) not Running"

READY_ALL=$(kubectl get pods -n oway --no-headers 2>/dev/null | awk '{print $2}' | grep -v "^1/1$\|^2/2$" | wc -l | tr -d ' ')
[ "$READY_ALL" = "0" ] && pass "All pods are Ready (1/1)" || fail "$READY_ALL pod(s) not Ready"

PHP84_COUNT=$(kubectl get pods -n oway -l app=php84 --no-headers 2>/dev/null | grep Running | wc -l | tr -d ' ')
[ "$PHP84_COUNT" -ge 2 ] && pass "php84 has >= 2 running pods ($PHP84_COUNT)" || fail "php84 has $PHP84_COUNT pods (min 2)"

PHP74_COUNT=$(kubectl get pods -n oway -l app=php74 --no-headers 2>/dev/null | grep Running | wc -l | tr -d ' ')
[ "$PHP74_COUNT" -ge 1 ] && pass "php74 has >= 1 running pod ($PHP74_COUNT)" || fail "php74 has $PHP74_COUNT pods (min 1)"

REDIS_UP=$(kubectl get pods -n oway -l app=redis --no-headers 2>/dev/null | grep "1/1.*Running" | wc -l | tr -d ' ')
[ "$REDIS_UP" = "1" ] && pass "Redis pod is Running" || fail "Redis pod not Running"

MYSQL_UP=$(kubectl get pods -n oway -l app=mysql --no-headers 2>/dev/null | grep "1/1.*Running" | wc -l | tr -d ' ')
[ "$MYSQL_UP" = "1" ] && pass "MySQL pod is Running" || fail "MySQL pod not Running"

# ══════════════════════════════════════════════════════
section "3. HPA Configuration"
# ══════════════════════════════════════════════════════

HPA84=$(kubectl get hpa php84-hpa -n oway --no-headers 2>/dev/null | wc -l | tr -d ' ')
[ "$HPA84" = "1" ] && pass "php84-hpa exists" || fail "php84-hpa not found"

HPA74=$(kubectl get hpa php74-hpa -n oway --no-headers 2>/dev/null | wc -l | tr -d ' ')
[ "$HPA74" = "1" ] && pass "php74-hpa exists" || fail "php74-hpa not found"

MAX84=$(kubectl get hpa php84-hpa -n oway -o jsonpath='{.spec.maxReplicas}' 2>/dev/null)
[ "$MAX84" = "8" ] && pass "php84-hpa maxReplicas = 8" || fail "php84-hpa maxReplicas = $MAX84 (expected 8)"

TARGET84=$(kubectl get hpa php84-hpa -n oway -o jsonpath='{.spec.metrics[0].resource.target.averageUtilization}' 2>/dev/null)
[ "$TARGET84" = "50" ] && pass "php84-hpa CPU target = 50%" || fail "php84-hpa CPU target = $TARGET84% (expected 50)"

METRICS_UP=$(kubectl get deployment metrics-server -n kube-system --no-headers 2>/dev/null | grep -v "0/1" | wc -l | tr -d ' ')
[ "$METRICS_UP" = "1" ] && pass "metrics-server is running" || fail "metrics-server not running (HPA จะไม่ทำงาน)"

# ══════════════════════════════════════════════════════
section "4. Redis Cache"
# ══════════════════════════════════════════════════════

# Flush cache
kubectl exec -n oway deployment/redis -- redis-cli FLUSHALL > /dev/null 2>&1
sleep 1

# Request 1 — miss
DB_MISS=$(get_db_ms "$LARAVEL")
CACHED_MISS=$(get_cached_count "$LARAVEL")

# Request 2 — hit
DB_HIT=$(get_db_ms "$LARAVEL")
CACHED_HIT=$(get_cached_count "$LARAVEL")

if [ -n "$DB_MISS" ] && [ -n "$DB_HIT" ]; then
  [ "$CACHED_MISS" = "0" ] && pass "Request 1: cache miss (no ⚡ cached badges)" || fail "Request 1: expected 0 cached, got $CACHED_MISS"
  [ "$CACHED_HIT" -ge 5 ] && pass "Request 2: cache hit ($CACHED_HIT queries cached)" || fail "Request 2: expected >=5 cached, got $CACHED_HIT"

  # DB time should drop significantly
  if (( $(echo "$DB_HIT < $DB_MISS" | bc -l 2>/dev/null || echo 1) )); then
    pass "Cache hit DB time ($DB_HIT ms) < miss ($DB_MISS ms)"
  else
    fail "Cache hit DB time ($DB_HIT ms) should be < miss ($DB_MISS ms)"
  fi

  [ "$(echo "$DB_HIT < 10" | bc -l 2>/dev/null)" = "1" ] && \
    pass "Cache hit DB time < 10ms ($DB_HIT ms)" || \
    fail "Cache hit DB time = $DB_HIT ms (expected <10ms)"
else
  fail "Could not get DB timing from Laravel (page error?)"
fi

# Laminas cache
kubectl exec -n oway deployment/redis -- redis-cli FLUSHALL > /dev/null 2>&1
sleep 1
CACHED_MISS2=$(get_cached_count "$LAMINAS")
CACHED_HIT2=$(get_cached_count "$LAMINAS")
[ "$CACHED_MISS2" = "0" ] && pass "Laminas: request 1 cache miss" || fail "Laminas: expected 0 cached, got $CACHED_MISS2"
[ "$CACHED_HIT2" -ge 5 ] && pass "Laminas: request 2 cache hit ($CACHED_HIT2 cached)" || fail "Laminas: expected >=5 cached, got $CACHED_HIT2"

# Redis key count
KEY_COUNT=$(kubectl exec -n oway deployment/redis -- redis-cli DBSIZE 2>/dev/null | tr -d '\r')
[ "$KEY_COUNT" -ge 6 ] && pass "Redis has >= 6 cached query keys ($KEY_COUNT keys)" || fail "Redis has $KEY_COUNT keys (expected >=6)"

# ══════════════════════════════════════════════════════
section "5. Load Balancing"
# ══════════════════════════════════════════════════════

echo "  (ยิง 10 requests เพื่อดู hostname กระจาย...)"
HOSTNAMES=()
for i in $(seq 1 10); do
  H=$(get_hostname "$LARAVEL")
  HOSTNAMES+=("$H")
done

UNIQUE=$(printf '%s\n' "${HOSTNAMES[@]}" | sort -u | wc -l | tr -d ' ')
[ "$UNIQUE" -ge 2 ] && pass "Load balancing: $UNIQUE unique hostnames in 10 requests" || \
  fail "Load balancing: only $UNIQUE hostname (expected >=2) — replicas อาจน้อยไป"

# ══════════════════════════════════════════════════════
section "6. Page Performance"
# ══════════════════════════════════════════════════════

# Warm up cache first
curl -sk "$LARAVEL" > /dev/null
curl -sk "$LAMINAS" > /dev/null
sleep 0.5

LARAVEL_MS=$(get_total_ms "$LARAVEL")
LAMINAS_MS=$(get_total_ms "$LAMINAS")

if [ -n "$LARAVEL_MS" ]; then
  [ "$(echo "$LARAVEL_MS < 500" | bc -l 2>/dev/null)" = "1" ] && \
    pass "Laravel total time < 500ms ($LARAVEL_MS ms, loops=100k)" || \
    fail "Laravel total time = $LARAVEL_MS ms (expected <500ms)"
else
  fail "Could not measure Laravel page time"
fi

if [ -n "$LAMINAS_MS" ]; then
  [ "$(echo "$LAMINAS_MS < 1000" | bc -l 2>/dev/null)" = "1" ] && \
    pass "Laminas total time < 1000ms ($LAMINAS_MS ms, loops=100k)" || \
    fail "Laminas total time = $LAMINAS_MS ms (expected <1000ms)"
else
  fail "Could not measure Laminas page time"
fi

# ══════════════════════════════════════════════════════
section "7. K8s API Data"
# ══════════════════════════════════════════════════════

API_BODY=$(curl -sk "$LARAVEL/k8s/api")

PODS_COUNT=$(echo "$API_BODY" | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d.get('pods',[])))" 2>/dev/null)
[ "${PODS_COUNT:-0}" -ge 4 ] && pass "K8s API returns >= 4 pods ($PODS_COUNT)" || fail "K8s API pods = $PODS_COUNT (expected >=4)"

HPA_COUNT=$(echo "$API_BODY" | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d.get('hpas',[])))" 2>/dev/null)
[ "${HPA_COUNT:-0}" -ge 2 ] && pass "K8s API returns >= 2 HPAs ($HPA_COUNT)" || fail "K8s API HPAs = $HPA_COUNT (expected >=2)"

HAS_TS=$(echo "$API_BODY" | python3 -c "import json,sys; d=json.load(sys.stdin); print('ok' if d.get('ts') else 'no')" 2>/dev/null)
[ "$HAS_TS" = "ok" ] && pass "K8s API returns timestamp" || fail "K8s API missing timestamp"

# ══════════════════════════════════════════════════════
section "8. PHP Extensions"
# ══════════════════════════════════════════════════════

POD84=$(kubectl get pods -n oway -l app=php84 -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
POD74=$(kubectl get pods -n oway -l app=php74 -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)

if [ -n "$POD84" ]; then
  kubectl exec -n oway "$POD84" -- php -m 2>/dev/null | grep -q "^redis$" && \
    pass "php84: redis extension loaded" || fail "php84: redis extension NOT loaded"
  kubectl exec -n oway "$POD84" -- php -m 2>/dev/null | grep -q "^pdo_mysql$" && \
    pass "php84: pdo_mysql extension loaded" || fail "php84: pdo_mysql extension NOT loaded"
  kubectl exec -n oway "$POD84" -- php -i 2>/dev/null | grep -q "opcache.jit => tracing" && \
    pass "php84: OPcache JIT = tracing" || fail "php84: OPcache JIT not enabled"
else
  skip "php84 pod not found"
fi

if [ -n "$POD74" ]; then
  kubectl exec -n oway "$POD74" -- php -m 2>/dev/null | grep -q "^redis$" && \
    pass "php74(8.3): redis extension loaded" || fail "php74(8.3): redis extension NOT loaded"
  kubectl exec -n oway "$POD74" -- php -i 2>/dev/null | grep -q "opcache.jit => tracing" && \
    pass "php74(8.3): OPcache JIT = tracing" || fail "php74(8.3): OPcache JIT not enabled"
else
  skip "php74 pod not found"
fi

# ══════════════════════════════════════════════════════
section "9. MySQL Tuning"
# ══════════════════════════════════════════════════════

MYSQL_POD=$(kubectl get pods -n oway -l app=mysql -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
if [ -n "$MYSQL_POD" ]; then
  POOL=$(kubectl exec -n oway "$MYSQL_POD" -- mysql -uoway -poway_secret -sNe "SELECT @@innodb_buffer_pool_size;" 2>/dev/null)
  [ "${POOL:-0}" -ge 268435456 ] && pass "InnoDB buffer pool >= 256MB ($((${POOL:-0}/1024/1024))MB)" || fail "InnoDB buffer pool = $((${POOL:-0}/1024/1024))MB (expected >=256)"

  MAX_CONN=$(kubectl exec -n oway "$MYSQL_POD" -- mysql -uoway -poway_secret -sNe "SELECT @@max_connections;" 2>/dev/null)
  [ "${MAX_CONN:-0}" -ge 200 ] && pass "MySQL max_connections >= 200 ($MAX_CONN)" || fail "MySQL max_connections = $MAX_CONN (expected >=200)"

  IO_CAP=$(kubectl exec -n oway "$MYSQL_POD" -- mysql -uoway -poway_secret -sNe "SELECT @@innodb_io_capacity;" 2>/dev/null)
  [ "${IO_CAP:-0}" -ge 500 ] && pass "InnoDB io_capacity >= 500 ($IO_CAP)" || fail "InnoDB io_capacity = $IO_CAP (expected >=500)"
else
  skip "MySQL pod not found"
fi

# ══════════════════════════════════════════════════════
section "10. Self-Healing"
# ══════════════════════════════════════════════════════

echo "  (kill 1 pod แล้วรอดู K8s สร้างใหม่...)"
BEFORE=$(kubectl get pods -n oway -l app=php84 --no-headers 2>/dev/null | grep Running | wc -l | tr -d ' ')
KILL_POD=$(kubectl get pods -n oway -l app=php84 -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)

if [ -n "$KILL_POD" ]; then
  kubectl delete pod -n owy "$KILL_POD" -n oway > /dev/null 2>&1
  sleep 15
  AFTER=$(kubectl get pods -n oway -l app=php84 --no-headers 2>/dev/null | grep Running | wc -l | tr -d ' ')
  [ "$AFTER" -ge "$BEFORE" ] && pass "Self-healing: pod count restored ($BEFORE → $AFTER running)" || fail "Self-healing: pods = $AFTER (expected >= $BEFORE)"

  STATUS=$(http_status "$LARAVEL")
  [ "$STATUS" = "200" ] && pass "Service still responds HTTP 200 after pod deletion" || fail "Service returned HTTP $STATUS after pod deletion"
else
  skip "Could not get php84 pod for self-healing test"
fi

# ══════════════════════════════════════════════════════
#  Summary
# ══════════════════════════════════════════════════════
TOTAL=$((PASS + FAIL + SKIP))
echo ""
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Test Results${NC}"
echo -e "${BOLD}════════════════════════════════════════════════${NC}"
echo -e "  Total : $TOTAL"
echo -e "  ${GREEN}Pass  : $PASS${NC}"
echo -e "  ${RED}Fail  : $FAIL${NC}"
echo -e "  ${YELLOW}Skip  : $SKIP${NC}"
echo ""
if [ "$FAIL" = "0" ]; then
  echo -e "  ${GREEN}${BOLD}✅ All tests passed!${NC}"
else
  echo -e "  ${RED}${BOLD}❌ $FAIL test(s) failed${NC}"
fi
echo ""
