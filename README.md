# OWAY K8s Multi-PHP Stack

> เรียนรู้ Kubernetes แบบ hands-on ด้วยการ deploy **Laravel (PHP 8.4)** + **Laminas MVC (PHP 8.3)** + **MariaDB** + **Redis** บน Kubernetes cluster เดียวกัน
> พร้อม HPA Auto-Scaling, SQL Benchmark 6 queries, Redis Cache, K8s Console realtime และ Automated Test Suite

---

## Architecture

```
                        Kubernetes Cluster (Docker Desktop)
┌──────────────────────────────────────────────────────────────────────────┐
│  Namespace: oway                                                         │
│                                                                          │
│  ┌────────────────┐                                                      │
│  │    Apache 2.4   │  HTTPS :666  /  HTTP :665                       │
│  │  reverse proxy  │  mkcert TLS (browser trusted)                       │
│  │  + SSL/TLS      │                                                     │
│  └───────┬────────┘                                                      │
│          │                                                               │
│          ├── laravel.localhost ──────┐                                    │
│          │                          ▼                                    │
│          │                ┌──────────────────┐                           │
│          │                │  PHP-FPM 8.4      │                           │
│          │                │  Laravel 13       │                           │
│          │                │  HPA: 2-8 pods    │                           │
│          │                │  + K8s Console    │                           │
│          │                └────────┬─────────┘                           │
│          │                         │                                     │
│          └── zend2.localhost ──────┐│                                     │
│                                   ▼▼                                     │
│                ┌──────────────────┐  ┌─────────────────┐                 │
│                │  PHP-FPM 8.3     │  │   Redis 7.2     │                 │
│                │  Laminas MVC     │  │   query cache   │                 │
│                │  + Doctrine ORM  │  │   128MB LRU     │                 │
│                │  HPA: 1-6 pods   │  │   TTL: 30s      │                 │
│                └────────┬─────────┘  └────────┬────────┘                 │
│                         │        cache miss    │                         │
│                         └──────────┬───────────┘                         │
│                                    ▼                                     │
│                         ┌─────────────────────┐                          │
│                         │   MariaDB 10.11     │                          │
│                         │   InnoDB 256MB pool │                          │
│                         │   PVC 1Gi storage   │                          │
│                         │   max_conn: 300     │                          │
│                         └─────────────────────┘                          │
│                                                                          │
│  ┌──────────────┐  ┌────────────────┐  ┌──────────────┐                 │
│  │  phpMyAdmin   │  │ metrics-server │  │  HPA x2      │                 │
│  │  :667       │  │ (CPU metrics)  │  │  (autoscale) │                 │
│  └──────────────┘  └────────────────┘  └──────────────┘                 │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Prerequisites

| Tool | Version | วิธีติดตั้ง |
|------|---------|------------|
| Docker Desktop | Latest | [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop) |
| Kubernetes | Enabled in Docker Desktop | Settings > Kubernetes > Enable |
| kubectl | Latest | มากับ Docker Desktop |
| mkcert | Latest | `brew install mkcert && mkcert -install` |
| metrics-server | Running in cluster | มากับ Docker Desktop K8s |

---

## Quick Start

```bash
# 1. Deploy ทั้งหมดด้วยคำสั่งเดียว (build images + apply manifests + port-forward)
./deploy.sh

# 2. รัน test suite ตรวจสอบทุกอย่าง (40+ assertions)
./test.sh

# 3. Sync code หลังแก้ไฟล์ (ไม่ต้อง redeploy)
./sync-code.sh
```

### deploy.sh ทำอะไรบ้าง?

```
Step 1   ตรวจหา K8s nodes (Docker Desktop)
Step 2   ติดตั้ง Laravel + Laminas ผ่าน Composer (ถ้ายังไม่มี)
Step 3   Build custom Docker images (oway-php83, oway-php84)
Step 4   Load images เข้าทุก K8s node
Step 5   Sync app code เข้า node containers
Step 6   แทนค่า hostPath ใน YAML
Step 7   สร้าง TLS certificate ด้วย mkcert
Step 8   Apply K8s manifests ตามลำดับ
Step 9   รอ pods พร้อม (timeout 180s)
Step 10  Port-forward สำหรับ browser access
```

---

## Access URLs

| Service | URL | รายละเอียด |
|---------|-----|-----------|
| Laravel SQL Benchmark | https://laravel.localhost:666 | 6 complex queries + Redis cache + CPU benchmark |
| Laminas + Doctrine Benchmark | https://zend2.localhost:666 | Doctrine ORM (QueryBuilder / DQL / NativeQuery) |
| K8s Auto-Scale Console | https://laravel.localhost:666/k8s | Realtime dashboard: pods, HPA, CPU metrics, load test |
| K8s API (JSON) | https://laravel.localhost:666/k8s/api | REST API สำหรับ cluster state |
| phpMyAdmin | http://localhost:667 | Database management UI |

---

## SQL Benchmark

ทั้ง Laravel และ Laminas ใช้ **database schema เดียวกัน** (สร้างอัตโนมัติ) พร้อม **6 complex queries** เปรียบเทียบ performance:

### Database Schema (~4,600 rows)

| Table | Records | Description |
|-------|---------|-------------|
| `bench_categories` | 24 | 8 parents + 2 children each (hierarchical) |
| `bench_products` | 128 | 8 per leaf category |
| `bench_customers` | 300 | Random customer data |
| `bench_orders` | 800 | Order records |
| `bench_order_items` | ~2,400 | ~3 items per order |
| `bench_reviews` | ~600 | Product reviews |

### 6 Complex Queries

| # | Query Type | SQL Features |
|---|-----------|-------------|
| 1 | 6-Table JOIN + Aggregation | JOIN 6 tables, GROUP BY, SUM, AVG, COUNT |
| 2 | CTE + Window Function | WITH CTE, RANK() OVER, Top-3 per category |
| 3 | Correlated Subquery | Subquery in WHERE, above-average analysis |
| 4 | Self-JOIN Hierarchy | Self-JOIN + EXISTS clause, parent-child |
| 5 | UNION ALL + Subquery | Multiple SELECTs, percentage calculations |
| 6 | GROUP BY + HAVING | Aggregate filter, customer spend analysis |

### Framework Comparison

| Approach | Laravel | Laminas |
|----------|---------|---------|
| Query Style | Raw `DB::select()` | Doctrine QueryBuilder / DQL / NativeQuery |
| ORM | ไม่ใช้ (raw SQL) | Doctrine ORM 3.x |
| Cache | `Cache::remember()` + Redis | Custom `cached()` wrapper + Redis |
| Cache TTL | 30 seconds | 30 seconds |

### Cache Performance

```
Request 1 (cache miss):  DB Time ~20-200ms  → queries hit MariaDB
Request 2 (cache hit):   DB Time <1ms       → queries hit Redis
                         ลด DB load ได้ >90%
```

---

## Kubernetes Features

### HPA Auto-Scaling

| Deployment | Min Pods | Max Pods | CPU Target | Scale Up | Scale Down |
|-----------|---------|---------|-----------|---------|-----------|
| php84 (Laravel) | 2 | 8 | 50% | +2 pods / 10s | Stabilize 30s |
| php74 (Laminas) | 1 | 6 | 50% | +2 pods / 10s | Stabilize 30s |

```
CPU > 50%  →  HPA เพิ่ม pods อัตโนมัติ (ภายใน 15-30 วินาที)
CPU < 50%  →  HPA ลด pods กลับ (รอ 30 วินาที stabilize)
```

### Self-Healing

- Pod ถูกลบ/ตาย → K8s สร้างใหม่ทันทีภายใน 10-15 วินาที
- Service ยังรับ traffic ได้ระหว่าง pod recovery (pods อื่นรับแทน)
- MySQL data ไม่หาย เพราะใช้ PersistentVolumeClaim

### Load Balancing

- K8s Service กระจาย traffic ไปยัง pods ทุกตัวแบบ round-robin
- รีเฟรชหน้าเว็บจะเห็น Pod Hostname เปลี่ยนสลับกัน

### Rolling Update & Rollback

```bash
# Zero-downtime update
kubectl rollout restart deployment/php84 -n oway

# Rollback ถ้ามีปัญหา
kubectl rollout undo deployment/php84 -n oway
```

---

## Performance Stack

| Layer | Technology | Configuration | Effect |
|-------|-----------|--------------|--------|
| PHP Runtime | OPcache + JIT (tracing) | 128MB cache, 64MB JIT buffer | PHP execution เร็วขึ้น 2-3x |
| Query Cache | Redis 7.2 Alpine | 128MB, LRU eviction, TTL 30s | DB load ลด >90% |
| Database | MariaDB 10.11 InnoDB | 256MB buffer pool, 300 max connections | Query เร็วขึ้น 2-4x |
| Web Server | Apache 2.4 Event MPM | FastCGI proxy to PHP-FPM | Concurrent connections |
| Auto-Scale | HPA v2 | CPU 50% target | รับ traffic spike |

---

## K8s Console (Realtime Dashboard)

เปิด https://laravel.localhost:666/k8s เพื่อดู:

- **Pod Status** — ชื่อ, สถานะ, CPU/Memory usage แบบ realtime
- **HPA Cards** — current/desired replicas, CPU utilization bar
- **Load Test Button** — กด fire 20 parallel requests เพื่อดู HPA scale ขึ้น
- **Auto-refresh** — ข้อมูลอัปเดตทุก 2 วินาที

ใช้ K8s API in-cluster ผ่าน ServiceAccount (`k8s-reader`) + RBAC

---

## Project Structure

```
k8s-multi-php/
│
├── deploy.sh                    # Deploy ทั้งหมดด้วยคำสั่งเดียว
├── test.sh                      # Automated test suite (40+ assertions)
├── sync-code.sh                 # Sync code เข้า K8s nodes (hot reload)
├── lab.md                       # Lab guide — 9 hands-on exercises
│
├── base/                        # Kubernetes manifests
│   ├── namespace.yaml           # Namespace: oway
│   ├── apache.yaml              # Apache reverse proxy + NodePort Service
│   ├── apache-config.yaml       # httpd.conf + VirtualHost configs (SSL)
│   ├── php84.yaml               # Laravel Deployment + ClusterIP Service
│   ├── php74.yaml               # Laminas Deployment + ClusterIP Service
│   ├── mysql.yaml               # MariaDB + PVC 1Gi + InnoDB tuning
│   ├── redis.yaml               # Redis 7.2 cache (128MB, LRU, no persist)
│   ├── hpa.yaml                 # HPA for php84 (2-8) + php74 (1-6)
│   ├── k8s-rbac.yaml            # ServiceAccount + Role + RoleBinding
│   ├── phpmyadmin.yaml          # phpMyAdmin + NodePort Service
│   ├── mysql-secret.yaml        # Database credentials
│   ├── mysql-init-configmap.yaml # SQL schema + seed data
│   ├── app-env-configmap.yaml   # Shared environment variables
│   └── app-code-configmap.yaml  # Sample PHP code (dev fallback)
│
├── docker/                      # Custom Docker images
│   ├── php84.Dockerfile         # PHP 8.4-FPM + OPcache JIT + Redis ext
│   └── php83.Dockerfile         # PHP 8.3-FPM + OPcache JIT + Redis ext
│
├── apps/                        # Application source code
│   ├── laravel/                 # Laravel 13.x (PHP 8.4)
│   │   ├── routes/web.php       #   / = SQL Benchmark, /k8s = Console
│   │   ├── app/                 #   Controllers, Models
│   │   └── ...
│   └── zend2/                   # Laminas MVC Skeleton (PHP 8.3)
│       ├── module/Application/  #   IndexController + Doctrine Entities
│       └── ...
│
├── certs/                       # TLS certificates (mkcert generated)
│   ├── oway-tls.crt
│   └── oway-tls.key
│
└── mysql/                       # (Reserved for MySQL data)
```

---

## Kubernetes Resources Summary

| Resource | Name | Details |
|----------|------|---------|
| **Namespace** | `oway` | Project isolation |
| **Deployment** | `apache` | 1 replica, reverse proxy + TLS |
| **Deployment** | `php84` | 2-8 replicas (HPA), Laravel |
| **Deployment** | `php74` | 1-6 replicas (HPA), Laminas |
| **Deployment** | `mysql` | 1 replica, MariaDB 10.11 |
| **Deployment** | `redis` | 1 replica, Redis 7.2 |
| **Deployment** | `phpmyadmin` | 1 replica |
| **Service** | `apache` | NodePort 665/666 |
| **Service** | `php84` | ClusterIP :9000 |
| **Service** | `php74` | ClusterIP :9000 |
| **Service** | `mysql` | ClusterIP :3306 |
| **Service** | `redis` | ClusterIP :6379 |
| **Service** | `phpmyadmin` | NodePort 667 |
| **HPA** | `php84-hpa` | CPU 50%, 2-8 pods |
| **HPA** | `php74-hpa` | CPU 50%, 1-6 pods |
| **PVC** | `mysql-pvc` | 1Gi ReadWriteOnce |
| **Secret** | `mysql-secret` | DB credentials |
| **Secret** | `oway-tls` | TLS cert + key |
| **ConfigMap** | `app-env` | Shared env vars |
| **ConfigMap** | `apache-config` | Apache config files |
| **ConfigMap** | `mysql-init-sql` | DB initialization SQL |
| **ServiceAccount** | `k8s-reader` | RBAC for K8s Console |

---

## Network Topology

```
External (Browser)
       │
       ├── :666 HTTPS ──► Apache ──┬── laravel.localhost ──► php84:9000 (Laravel)
       │                             └── zend2.localhost   ──► php74:9000 (Laminas)
       │
       └── :667  HTTP  ──► phpMyAdmin ──► mysql:3306

Internal (ClusterIP)
       php84/php74 ──► redis:6379   (cache hit <1ms)
                   ──► mysql:3306   (cache miss only)
```

---

## Docker Compose vs Kubernetes

สำหรับคนที่คุ้นเคย Docker Compose — นี่คือ mapping:

| Docker Compose | Kubernetes | ไฟล์ในโปรเจกต์ |
|---------------|-----------|---------------|
| `docker-compose.yml` | หลายไฟล์ `.yaml` | `base/*.yaml` |
| `services:` | Deployment + Service | `php84.yaml`, `apache.yaml` |
| `image:` | `spec.containers[].image` | ใน Deployment YAML |
| `ports:` | Service (NodePort/ClusterIP) | `apache.yaml` (666) |
| `volumes:` | PersistentVolumeClaim | `mysql.yaml` (1Gi PVC) |
| `environment:` | ConfigMap / Secret | `app-env-configmap.yaml` |
| `docker-compose up` | `./deploy.sh` | deploy.sh |
| `docker-compose down` | `kubectl delete namespace oway` | - |
| `docker-compose ps` | `kubectl get pods -n oway` | - |
| `docker-compose logs` | `kubectl logs -n oway` | - |
| *(ไม่มี)* | HPA Auto-Scaling | `hpa.yaml` |
| *(ไม่มี)* | Self-Healing | Built-in K8s |
| *(ไม่มี)* | Rolling Update | Built-in K8s |
| *(ไม่มี)* | Load Balancing | K8s Service |

---

## Lab Exercises

ดู [lab.md](lab.md) สำหรับ hands-on exercises ทั้ง 9 labs:

| Lab | หัวข้อ | Concepts |
|-----|--------|----------|
| 1 | K8s Load Balancing | Service round-robin, hostname distribution |
| 2 | SQL Benchmark & Redis Cache | Cache miss/hit, TTL expiry, query timing |
| 3 | HPA Auto-Scaling | CPU trigger, scale up/down, metrics-server |
| 4 | Self-Healing | Pod deletion recovery, service continuity |
| 5 | Rolling Update | Zero-downtime deployment, rollback |
| 6 | Resource Limits | CPU/Memory throttling, resource quotas |
| 7 | K8s Console Realtime | In-cluster API, ServiceAccount, RBAC |
| 8 | Database Scaling | InnoDB tuning, connection pools |
| 9 | Test Case Summary | 16 test scenarios, pass criteria |

---

## Automated Test Suite

```bash
./test.sh
```

รัน 40+ test assertions ครอบคลุม:

| Category | Tests |
|----------|-------|
| Connectivity | HTTP 200 ทุก endpoint (Laravel, Laminas, K8s Console, K8s API) |
| Pod Health | ทุก pod Running + Ready, minimum replicas met |
| HPA Config | Exists, maxReplicas, CPU target, metrics-server running |
| Redis Cache | Cache miss → hit, timing improvement >90%, key count |
| Load Balancing | Hostname distribution >= 2 unique in 10 requests |
| Page Performance | Laravel <500ms, Laminas <1000ms (with cache) |
| K8s API Data | JSON schema validation (pods, HPAs, timestamp) |
| PHP Extensions | redis, pdo_mysql, OPcache JIT = tracing |
| MySQL Tuning | InnoDB buffer >=256MB, max_conn >=200, io_capacity >=500 |
| Self-Healing | Pod deletion + recovery + service continuity |

---

## Common Commands

```bash
# ────────────────── Deploy & Sync ──────────────────
./deploy.sh                                    # Deploy ทั้งหมด
./sync-code.sh                                 # Sync code (hot reload)
./test.sh                                      # Run test suite

# ────────────────── Monitor ──────────────────
kubectl get pods -n oway                       # ดู pods ทั้งหมด
kubectl get hpa -n oway                        # ดู HPA status + CPU%
kubectl top pods -n oway                       # ดู CPU/Memory usage realtime
kubectl get all -n oway                        # ดูทุก resource

# ────────────────── Logs ──────────────────
kubectl logs -f deployment/php84 -n oway       # Laravel logs
kubectl logs -f deployment/php74 -n oway       # Laminas logs
kubectl logs -f deployment/apache -n oway      # Apache access logs
kubectl logs -f deployment/mysql -n oway       # MySQL logs

# ────────────────── Debug ──────────────────
kubectl exec -it deployment/mysql -n oway -- mysql -u oway -poway_secret mex_sellin
kubectl exec -it deployment/redis -n oway -- redis-cli
kubectl describe pod <pod-name> -n oway        # ดู events + status

# ────────────────── Scale ──────────────────
kubectl scale deployment php84 -n oway --replicas=5    # Manual scale (HPA override กลับ)
kubectl rollout restart deployment/php84 -n oway       # Rolling update
kubectl rollout undo deployment/php84 -n oway          # Rollback

# ────────────────── Cleanup ──────────────────
kubectl delete namespace oway                  # ลบทุกอย่าง
pkill -f 'kubectl port-forward'                # หยุด port-forward
```

---

## Tech Stack

| Component | Technology | Version |
|-----------|-----------|---------|
| Container Runtime | Docker Desktop | Latest |
| Orchestration | Kubernetes | Docker Desktop built-in |
| PHP (Laravel) | PHP-FPM | 8.4 |
| PHP (Laminas) | PHP-FPM | 8.3 |
| Framework | Laravel | 13.x |
| Framework | Laminas MVC | Latest |
| ORM | Doctrine | 3.x (Laminas only) |
| Web Server | Apache httpd | 2.4 |
| Database | MariaDB | 10.11 |
| Cache | Redis | 7.2 Alpine |
| DB Admin | phpMyAdmin | Latest |
| TLS | mkcert | Latest |
| PHP Extensions | OPcache JIT, Redis, PDO MySQL, intl, zip, mbstring | - |

---

## Environment Variables

ตั้งค่าผ่าน ConfigMap (`app-env`) — ทุก pod ได้ค่าเดียวกัน:

| Variable | Value | Description |
|----------|-------|-------------|
| `TZ` | `Asia/Bangkok` | System timezone |
| `APP_TIMEZONE` | `Asia/Bangkok` | Application timezone |
| `DB_HOST` | `mysql` | MariaDB service name (K8s DNS) |
| `DB_PORT` | `3306` | MariaDB port |
| `DB_DATABASE` | `mex_sellin` | Database name |
| `REDIS_HOST` | `redis` | Redis service name (K8s DNS) |
| `REDIS_PORT` | `6379` | Redis port |
| `CACHE_TTL` | `30` | Query cache lifetime (seconds) |

---

## Troubleshooting

### Pods ไม่ขึ้น (Pending / CrashLoopBackOff)

```bash
kubectl describe pod <pod-name> -n oway    # ดู Events section
kubectl logs <pod-name> -n oway            # ดู container logs
```

### HPA ไม่ scale (TARGETS = \<unknown\>)

```bash
# ตรวจว่า metrics-server ทำงาน
kubectl get deployment metrics-server -n kube-system
kubectl top pods -n oway    # ต้องแสดง CPU/Memory ได้
```

### Browser ขึ้น certificate warning

```bash
# ติดตั้ง mkcert CA
mkcert -install

# สร้าง cert ใหม่
rm certs/oway-tls.*
./deploy.sh    # จะสร้างใหม่อัตโนมัติ
```

### Port-forward หลุด

```bash
pkill -f 'kubectl port-forward'
kubectl port-forward svc/apache 665:665 666:666 -n oway &
kubectl port-forward svc/phpmyadmin 667:667 -n oway &
```

### Code ไม่อัปเดตหลังแก้ไฟล์

```bash
./sync-code.sh    # Sync code เข้า K8s nodes
# รีเฟรช browser (Ctrl+Shift+R = hard refresh)
```

---

## License

This project is for educational purposes.
