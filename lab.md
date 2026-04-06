# Lab Guide — OWAY K8s Multi-PHP

คู่มือการทดสอบและ test case สำหรับ Kubernetes concepts ทั้งหมดในโปรเจกต์นี้

---

## สารบัญ

1. [Setup & Deploy](#1-setup--deploy)
2. [Lab 1 — K8s Load Balancing](#lab-1--k8s-load-balancing)
3. [Lab 2 — SQL Benchmark & Redis Cache](#lab-2--sql-benchmark--redis-cache)
4. [Lab 3 — HPA Auto-Scaling](#lab-3--hpa-auto-scaling)
5. [Lab 4 — Self-Healing](#lab-4--self-healing)
6. [Lab 5 — Rolling Update (Zero Downtime)](#lab-5--rolling-update-zero-downtime)
7. [Lab 6 — Resource Limits & Throttling](#lab-6--resource-limits--throttling)
8. [Lab 7 — K8s Console Realtime](#lab-7--k8s-console-realtime)
9. [Test Case Summary](#test-case-summary)

---

## 1. Setup & Deploy

### Prerequisites
- Docker Desktop + Kubernetes enabled
- mkcert (สำหรับ TLS cert)
- kubectl

### Deploy

```bash
./deploy.sh
```

### Verify ทุกอย่างพร้อม

```bash
kubectl get pods -n oway
```

**Expected output:**

```
NAME                          READY   STATUS    RESTARTS
apache-xxx                    1/1     Running   0
mysql-xxx                     1/1     Running   0
redis-xxx                     1/1     Running   0
php74-xxx (1-6 pods)          1/1     Running   0
php84-xxx (2-8 pods)          1/1     Running   0
phpmyadmin-xxx                1/1     Running   0
```

**Checklist:**
- [ ] ทุก pod STATUS = Running
- [ ] ทุก pod READY = 1/1
- [ ] ไม่มี CrashLoopBackOff หรือ Error

---

## Lab 1 — K8s Load Balancing

**Concept:** K8s Service กระจาย traffic ไปยัง pods หลายตัวอัตโนมัติ (round-robin)

### Test 1.1 — Hostname เปลี่ยนทุก request

**วิธีทดสอบ:**

```bash
for i in $(seq 1 10); do
  curl -sk https://laravel.localhost:666 | grep -o 'val r">[^<]*'
done
```

**Expected:** hostname เปลี่ยนสลับกันระหว่าง pods (เช่น php84-abc-xxx, php84-def-xxx)

**Pass criteria:** เห็น hostname อย่างน้อย 2 ค่าต่างกันใน 10 requests

---

### Test 1.2 — เปิดหน้า Benchmark แล้วรีเฟรช

**URL:** https://laravel.localhost:666

สังเกตบรรทัด:
```
Pod Hostname — K8s Load Balancing
php84-xxxxxxxxx-xxxxx
```

**Pass criteria:** รีเฟรช 5 ครั้ง เห็น hostname เปลี่ยนอย่างน้อย 1 ครั้ง

---

### Test 1.3 — ดู load กระจายทาง kubectl

```bash
kubectl get pods -n oway -o wide
```

**Expected:** pods กระจายอยู่บน node ต่างกัน (desktop-worker, desktop-control-plane)

---

## Lab 2 — SQL Benchmark & Redis Cache

**Concept:** Redis cache query results — request แรก hit MySQL, request ถัดไป hit Redis (<1ms)

### Test 2.1 — Cache Miss vs Cache Hit (Laravel)

**Step 1: Flush Redis**
```bash
kubectl exec -n oway deployment/redis -- redis-cli FLUSHALL
```

**Step 2: Request แรก (cache miss)**
```bash
curl -sk https://laravel.localhost:666 | grep -o '[0-9.]* ms'
```
สังเกต DB Time — ควรเป็น 20-200ms

**Step 3: Request ที่ 2 (cache hit)**
```bash
curl -sk https://laravel.localhost:666 | grep -o '[0-9.]* ms\|cached'
```

**Expected:**
- Request 1: DB Time ~20-200ms, ไม่มี ⚡ cached
- Request 2: DB Time <5ms, มี ⚡ cached บนทุก query

**Pass criteria:** DB Time ลดลง >90% ระหว่าง request 1 กับ 2

---

### Test 2.2 — Cache Miss vs Cache Hit (Laminas + Doctrine ORM)

เหมือน Test 2.1 แต่ใช้ URL: https://zend2.localhost:666

**Expected:**
- Request 1: DB Time ~100-500ms (Doctrine ORM + JOIN ซับซ้อน)
- Request 2: DB Time <5ms, ⚡ cached

---

### Test 2.3 — ดู Redis keys ที่ cache ไว้

```bash
kubectl exec -n oway deployment/redis -- redis-cli KEYS "bq:*"
```

**Expected:** เห็น keys รูปแบบ `bq:xxxxxxxx` (md5 ของ query)

```bash
kubectl exec -n oway deployment/redis -- redis-cli TTL "bq:<key>"
```

**Expected:** TTL ระหว่าง 0-30 วินาที

---

### Test 2.4 — Query Complexity Comparison

เปิดทั้งสองหน้าพร้อมกัน แล้วเปรียบ query time:

| Query | Laravel (raw SQL) | Laminas (Doctrine ORM) |
|---|---|---|
| 6-Table JOIN | ~20ms | ~30ms |
| CTE + RANK() | ~3ms | ~5ms |
| Correlated Subquery | ~1ms | ~1ms |
| Self-JOIN Hierarchy | ~0.5ms | ~1ms |
| UNION ALL | ~1ms | ~2ms |
| GROUP BY + HAVING | ~3ms | ~4ms |

*ตัวเลขนี้เป็น cache miss — หลัง cache hit จะ <1ms ทุก query*

---

### Test 2.5 — TTL Expiry

```bash
# 1. Flush และดู miss
kubectl exec -n oway deployment/redis -- redis-cli FLUSHALL
curl -sk https://laravel.localhost:666 > /dev/null  # miss

# 2. รอ 31 วินาที
sleep 31

# 3. Request ใหม่ — ควร miss อีกครั้ง (TTL หมด)
curl -sk https://laravel.localhost:666 | grep -o 'val y">[0-9.]*'
```

**Pass criteria:** DB Time กลับมาสูงหลัง 30 วินาที

---

## Lab 3 — HPA Auto-Scaling

**Concept:** HPA วัด CPU% ทุก 15 วินาที ถ้าเกิน target 50% → เพิ่ม pods อัตโนมัติ

### Test 3.1 — ดู HPA Status

```bash
kubectl get hpa -n oway
```

**Expected:**
```
NAME        REFERENCE          TARGETS       MINPODS  MAXPODS  REPLICAS
php74-hpa   Deployment/php74   cpu: X%/50%   1        6        1
php84-hpa   Deployment/php84   cpu: X%/50%   2        8        2
```

---

### Test 3.2 — Trigger Auto-Scale ด้วย Load Test

**วิธีที่ 1: ผ่าน K8s Console**

เปิด https://laravel.localhost:666/k8s แล้วกด **🔥 Laravel Load (20 req)**

สังเกต:
- CPU bar ขึ้น
- Desired replicas เพิ่ม
- Pod icons เปลี่ยนสี (🟦 → 🟡 Pending → 🟦 Running)

---

**วิธีที่ 2: Terminal**

```bash
# ยิง 20 parallel requests พร้อมกัน
for i in $(seq 1 20); do
  curl -sk "https://laravel.localhost:666/?loops=1000000" -o /dev/null &
done
wait

# ดู HPA scale ขึ้น
watch -n 3 kubectl get hpa,pods -n oway -l app=php84
```

**Expected sequence:**
1. CPU% ขึ้นเกิน 50%
2. DESIRED replicas เพิ่ม (ภายใน 15-30 วินาที)
3. Pod ใหม่ STATUS: ContainerCreating → Running
4. หยุด load → รอ 30 วินาที → pods ลดลงกลับ

**Pass criteria:**
- [ ] Scale up เกิดขึ้นภายใน 30 วินาทีหลัง CPU > 50%
- [ ] Scale down เกิดขึ้นภายใน 60 วินาทีหลังหยุด load
- [ ] ไม่เกิน maxReplicas (8 สำหรับ php84)
- [ ] ไม่ต่ำกว่า minReplicas (2 สำหรับ php84)

---

### Test 3.3 — ตรวจสอบ Scale Down

```bash
# หลังหยุด load รอ 60 วินาที
kubectl get hpa php84-hpa -n oway
kubectl get pods -n oway -l app=php84 --no-headers | wc -l
```

**Expected:** replicas กลับมาเป็น 2 (minReplicas)

---

### Test 3.4 — ดู HPA Events

```bash
kubectl describe hpa php84-hpa -n oway | tail -20
```

**Expected:** เห็น events เช่น:
```
ScalingActive   True    ValidMetricFound
SuccessfulRescale  ...  New size: 4; reason: cpu resource utilization (percentage of request) above target
```

---

## Lab 4 — Self-Healing

**Concept:** K8s restart pods ที่ตายอัตโนมัติ ไม่มี downtime

### Test 4.1 — Kill Pod แล้วดู K8s สร้างใหม่

**Terminal 1: watch pods**
```bash
watch -n 1 kubectl get pods -n oway -l app=php84
```

**Terminal 2: kill pod**
```bash
kubectl delete pod -n oway $(kubectl get pods -n oway -l app=php84 -o jsonpath='{.items[0].metadata.name}')
```

**Expected:** pod ถูกลบ → K8s สร้างใหม่ทันที → STATUS: ContainerCreating → Running ภายใน 10-15 วินาที

**Pass criteria:**
- [ ] Pod ใหม่ขึ้นมาอัตโนมัติ (ไม่ต้องสั่งเอง)
- [ ] ระหว่างที่ pod ตาย — pods อื่นยังรับ traffic ได้
- [ ] RESTARTS counter เพิ่มขึ้น 0 → 0 (pod ใหม่ ไม่ใช่ restart เดิม)

---

### Test 4.2 — ทดสอบว่า Service ไม่ขาด

**Terminal 1: ยิง requests ต่อเนื่อง**
```bash
while true; do
  STATUS=$(curl -sk -o /dev/null -w "%{http_code}" https://laravel.localhost:666)
  echo "$(date +%H:%M:%S) HTTP $STATUS"
  sleep 0.5
done
```

**Terminal 2: kill pod**
```bash
kubectl delete pod -n oway $(kubectl get pods -n oway -l app=php84 -o jsonpath='{.items[0].metadata.name}')
```

**Expected:** HTTP status ควรเป็น 200 ตลอด อาจมี 1-2 requests ที่ช้าลงแต่ไม่ควรเป็น 500

---

### Test 4.3 — Kill MySQL แล้วดูว่า pod กลับมาเอง

```bash
kubectl delete pod -n oway $(kubectl get pods -n oway -l app=mysql -o jsonpath='{.items[0].metadata.name}')
watch -n 1 kubectl get pods -n oway -l app=mysql
```

**Expected:** MySQL pod ใหม่ขึ้นมาภายใน 30 วินาที พร้อมข้อมูลเดิม (เพราะใช้ PVC)

---

## Lab 5 — Rolling Update (Zero Downtime)

**Concept:** K8s อัปเดต image ทีละ pod ไม่ shutdown ทั้งหมดพร้อมกัน

### Test 5.1 — Rolling Update

**Terminal 1: ยิง requests ต่อเนื่อง**
```bash
while true; do
  curl -sk -o /dev/null -w "%{http_code} " https://laravel.localhost:666
  sleep 0.3
done
```

**Terminal 2: trigger rolling update**
```bash
# เปลี่ยน annotation เพื่อ trigger restart
kubectl rollout restart deployment/php84 -n oway
```

**Expected:** HTTP 200 ตลอด ไม่มี downtime แม้ขณะ update

```bash
# ดู progress
kubectl rollout status deployment/php84 -n oway
```

---

### Test 5.2 — Rollback

```bash
# ดู history
kubectl rollout history deployment/php84 -n oway

# Rollback ไป revision ก่อนหน้า
kubectl rollout undo deployment/php84 -n oway

# ยืนยัน rollback สำเร็จ
kubectl rollout status deployment/php84 -n oway
```

---

## Lab 6 — Resource Limits & Throttling

**Concept:** K8s จำกัด CPU/Memory ของแต่ละ container

### Test 6.1 — ดู Resource Usage

```bash
kubectl top pods -n oway
```

**Expected:**
```
NAME              CPU(cores)   MEMORY(bytes)
mysql-xxx         50m-498m     200-512Mi
php84-xxx         10m-500m     30-256Mi
redis-xxx         2m-10m       5-160Mi
```

---

### Test 6.2 — ดู Resource Limits

```bash
kubectl describe pod -n oway $(kubectl get pods -n oway -l app=php84 -o jsonpath='{.items[0].metadata.name}') | grep -A6 "Limits\|Requests"
```

**Expected:**
```
Limits:
  cpu:     500m
  memory:  256Mi
Requests:
  cpu:     100m
  memory:  64Mi
```

---

### Test 6.3 — CPU Throttle Test

```bash
# ยิง CPU-heavy request แล้วดู throttle
curl -sk "https://laravel.localhost:666/?loops=5000000" -o /dev/null &
sleep 2
kubectl top pods -n oway -l app=php84
```

**Expected:** CPU ของ pod พุ่งขึ้น แต่ไม่เกิน limit (500m)

---

## Lab 7 — K8s Console Realtime

**Concept:** ดู cluster state แบบ realtime ผ่านหน้าเว็บ โดยใช้ K8s API in-cluster

**URL:** https://laravel.localhost:666/k8s

### Test 7.1 — API Endpoint

```bash
curl -sk https://laravel.localhost:666/k8s/api | python3 -m json.tool | head -30
```

**Expected:** JSON มี keys: `pods`, `hpas`, `deployments`, `ts`, `hostname`

---

### Test 7.2 — Console แสดงข้อมูลถูกต้อง

เปิด https://laravel.localhost:666/k8s แล้วตรวจ:

- [ ] HPA cards แสดง php84-hpa และ php74-hpa
- [ ] Pods table แสดง pods ทั้งหมดใน namespace oway
- [ ] CPU bar อัปเดตทุก 2 วินาที
- [ ] Timestamp มุมบนขวาเปลี่ยนทุก 2 วินาที

---

### Test 7.3 — Load Test ผ่าน Console

กด **🔥 Laravel Load (20 req)** แล้วสังเกต:

- [ ] CPU bar ของ php84-hpa ขึ้น
- [ ] Badge เปลี่ยนจาก ✓ Stable → ⚡ Scaling
- [ ] Pod icon เปลี่ยนสี (🟦 active, 🟡 pending)
- [ ] กด Stop → pods ค่อยๆ ลดลง

---

## Test Case Summary

| # | Test | URL/Command | Pass Criteria |
|---|------|-------------|---------------|
| 1.1 | Load Balancing | curl loop x10 | hostname เปลี่ยน ≥2 ค่า |
| 2.1 | Redis Cache Miss | FLUSHALL + curl | DB Time >20ms |
| 2.2 | Redis Cache Hit | curl อีกครั้ง | DB Time <5ms + ⚡ cached |
| 2.3 | Cache TTL | รอ 31s | DB Time กลับมาสูง |
| 3.1 | HPA Status | kubectl get hpa | TARGETS แสดง CPU% |
| 3.2 | Scale Up | load test | replicas เพิ่มภายใน 30s |
| 3.3 | Scale Down | หยุด load | replicas ลดภายใน 60s |
| 4.1 | Self-Healing | delete pod | pod ใหม่ขึ้นภายใน 15s |
| 4.2 | No Downtime | curl loop + delete pod | HTTP 200 ตลอด |
| 4.3 | DB Recovery | delete mysql pod | pod กลับมาพร้อมข้อมูล |
| 5.1 | Rolling Update | rollout restart | HTTP 200 ตลอด |
| 5.2 | Rollback | rollout undo | deployment กลับ revision เดิม |
| 6.1 | Resource Usage | kubectl top | CPU/Memory ไม่เกิน limit |
| 7.1 | K8s API | /k8s/api | JSON ถูกต้อง |
| 7.2 | Console UI | /k8s | แสดงข้อมูล realtime |
| 7.3 | Load via Console | กด Load button | เห็น scale ใน UI |

---

## Quick Commands Reference

```bash
# Deploy ทั้งหมด
./deploy.sh

# Sync code (ไม่ต้อง redeploy)
./sync-code.sh

# ดูทุกอย่าง
kubectl get all -n oway

# ดู HPA + CPU usage
kubectl get hpa -n oway && kubectl top pods -n oway

# Flush Redis cache
kubectl exec -n oway deployment/redis -- redis-cli FLUSHALL

# เข้า MySQL
kubectl exec -it deployment/mysql -n oway -- mysql -u oway -poway_secret mex_sellin

# ดู Redis keys
kubectl exec -n oway deployment/redis -- redis-cli KEYS "*"

# ดู logs
kubectl logs -f deployment/php84 -n oway -c php84
kubectl logs -f deployment/php74 -n oway -c php74

# Kill pod (test self-healing)
kubectl delete pod -n oway <pod-name>

# Scale manual
kubectl scale deployment php84 -n oway --replicas=5

# Rollback
kubectl rollout undo deployment/php84 -n oway

# ลบทั้งหมด
kubectl delete namespace oway
```
