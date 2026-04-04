<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════
//  สร้างตาราง benchmark + seed data (ถ้ายังไม่มี)
// ══════════════════════════════════════════════════════════
function ensureBenchSchema(): void
{
    $exists = DB::selectOne(
        "SELECT COUNT(*) as cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'bench_categories'"
    );
    if ($exists->cnt > 0) return;

    DB::unprepared("
        CREATE TABLE bench_categories (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            name     VARCHAR(100) NOT NULL,
            slug     VARCHAR(100) NOT NULL,
            INDEX idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE bench_products (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name        VARCHAR(200) NOT NULL,
            sku         VARCHAR(60)  NOT NULL UNIQUE,
            price       DECIMAL(10,2) NOT NULL,
            cost        DECIMAL(10,2) NOT NULL,
            stock       INT DEFAULT 0,
            is_active   TINYINT(1) DEFAULT 1,
            INDEX idx_cat (category_id),
            INDEX idx_price (price)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE bench_customers (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            email      VARCHAR(150) NOT NULL UNIQUE,
            city       VARCHAR(80),
            country    CHAR(2) DEFAULT 'TH',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_country (country)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE bench_orders (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            status      ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
            total       DECIMAL(10,2) DEFAULT 0.00,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_status  (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE bench_order_items (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            order_id   INT NOT NULL,
            product_id INT NOT NULL,
            quantity   INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            INDEX idx_order   (order_id),
            INDEX idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE bench_reviews (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            product_id  INT NOT NULL,
            customer_id INT NOT NULL,
            rating     TINYINT NOT NULL,
            body       TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product (product_id),
            INDEX idx_rating  (rating)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    seedBenchData();
}

function seedBenchData(): void
{
    $pdo = DB::getPdo();

    // ── Categories: 8 parent + 2 children each = 24 total ──
    $parents = ['Electronics','Clothing','Sports','Food & Drink','Books','Home & Living','Beauty','Toys'];
    $children = [
        ['Mobile Phones','Laptops & Tablets'],
        ['T-Shirts & Tops','Jeans & Pants'],
        ['Running','Swimming & Water Sports'],
        ['Snacks','Beverages'],
        ['Fiction','Non-Fiction & Education'],
        ['Furniture','Kitchen & Dining'],
        ['Skincare','Makeup & Color'],
        ['Action Figures','Educational Toys'],
    ];
    $catIds = [];
    $leafIds = [];
    foreach ($parents as $i => $pname) {
        $pdo->exec("INSERT INTO bench_categories (name, slug, parent_id) VALUES ('$pname','".strtolower(str_replace(' ','_',$pname))."',NULL)");
        $pid = $pdo->lastInsertId();
        $catIds[] = $pid;
        foreach ($children[$i] as $cname) {
            $pdo->exec("INSERT INTO bench_categories (name, slug, parent_id) VALUES ('$cname','".strtolower(str_replace([' ','&'],['_',''],$cname))."',$pid)");
            $leafIds[] = $pdo->lastInsertId();
        }
    }

    // ── Products: 8 per leaf category = 128 products ──
    $adjectives = ['Pro','Plus','Ultra','Lite','Max','Mini','Smart','Elite'];
    $materials  = ['Carbon','Silver','Gold','Black','White','Blue','Red','Green'];
    $prodRows = [];
    $prodId = 1;
    foreach ($leafIds as $li => $catId) {
        for ($j = 0; $j < 8; $j++) {
            $price = round(rand(99, 9999) + rand(0,99)/100, 2);
            $cost  = round($price * (0.4 + rand(0,20)/100), 2);
            $stock = rand(0, 999);
            $adj   = $adjectives[$j];
            $mat   = $materials[$li % 8];
            $name  = addslashes("$adj $mat Product ".($li*8+$j+1));
            $sku   = "SKU-".str_pad($prodId, 5, '0', STR_PAD_LEFT);
            $prodRows[] = "($catId,'$name','$sku',$price,$cost,$stock,1)";
            $prodId++;
        }
    }
    $pdo->exec("INSERT INTO bench_products (category_id,name,sku,price,cost,stock,is_active) VALUES ".implode(',',$prodRows));

    // ── Customers: 300 ──
    $cities   = ['Bangkok','Chiang Mai','Phuket','Pattaya','Khon Kaen','Hat Yai','Udon Thani','Nakhon Si Thammarat'];
    $custRows = [];
    for ($i = 1; $i <= 300; $i++) {
        $name  = addslashes("Customer $i Name");
        $email = "customer$i@bench.local";
        $city  = $cities[$i % 8];
        $custRows[] = "('$name','$email','$city','TH')";
    }
    $pdo->exec("INSERT INTO bench_customers (name,email,city,country) VALUES ".implode(',',$custRows));

    // ── Orders: 800 ──
    $statuses = ['pending','processing','shipped','delivered','cancelled'];
    $orderRows = [];
    for ($i = 1; $i <= 800; $i++) {
        $custId = rand(1, 300);
        $status = $statuses[$i % 5];
        $orderRows[] = "($custId,'$status',0.00,DATE_SUB(NOW(), INTERVAL ".rand(1,365)." DAY))";
    }
    $pdo->exec("INSERT INTO bench_orders (customer_id,status,total,created_at) VALUES ".implode(',',$orderRows));

    // ── Order Items: ~3 per order = ~2400 ──
    $itemRows = [];
    for ($orderId = 1; $orderId <= 800; $orderId++) {
        $itemCount = rand(1, 5);
        $usedProds = [];
        for ($k = 0; $k < $itemCount; $k++) {
            do { $prodId = rand(1, 128); } while (in_array($prodId, $usedProds));
            $usedProds[] = $prodId;
            $qty   = rand(1, 10);
            $price = round(rand(99, 9999) + rand(0,99)/100, 2);
            $itemRows[] = "($orderId,$prodId,$qty,$price)";
        }
    }
    $pdo->exec("INSERT INTO bench_order_items (order_id,product_id,quantity,unit_price) VALUES ".implode(',',$itemRows));

    // อัปเดต order totals
    $pdo->exec("UPDATE bench_orders o
        JOIN (SELECT order_id, SUM(quantity*unit_price) as t FROM bench_order_items GROUP BY order_id) s
        ON o.id = s.order_id
        SET o.total = s.t");

    // ── Reviews: ~600 ──
    $revRows = [];
    $revSet  = [];
    $count   = 0;
    while ($count < 600) {
        $prodId = rand(1, 128);
        $custId = rand(1, 300);
        $key    = "$prodId-$custId";
        if (isset($revSet[$key])) continue;
        $revSet[$key] = true;
        $rating  = rand(1, 5);
        $body    = addslashes("Review comment rating $rating for product $prodId");
        $revRows[] = "($prodId,$custId,$rating,'$body')";
        $count++;
    }
    $pdo->exec("INSERT INTO bench_reviews (product_id,customer_id,rating,body) VALUES ".implode(',',$revRows));
}

// ══════════════════════════════════════════════════════════
//  Helper: run query + time it
// ══════════════════════════════════════════════════════════
function timedQuery(string $sql, string $label, int $ttl = 30): array
{
    $key = 'bq:' . md5($sql);
    $t   = microtime(true);
    $hit = true;
    $rows = Cache::remember($key, $ttl, function () use ($sql, &$hit) {
        $hit = false;
        return DB::select($sql);
    });
    return [
        'label'  => $label,
        'ms'     => round((microtime(true) - $t) * 1000, 2),
        'rows'   => count($rows),
        'data'   => $rows,
        'cached' => $hit,
    ];
}

// ══════════════════════════════════════════════════════════
//  Route
// ══════════════════════════════════════════════════════════
Route::get('/', function () {
    $pageStart = microtime(true);

    // CPU benchmark
    $loops    = max(1, min((int)request('loops', 100000), 5000000));
    $cpuStart = microtime(true);
    $x = 0;
    for ($i = 0; $i < $loops; $i++) { $x += sqrt($i) * sin($i); }
    $cpuTime = round((microtime(true) - $cpuStart) * 1000, 2);

    // DB init (runs only once ever)
    $dbInitStart = microtime(true);
    ensureBenchSchema();
    $dbInitMs = round((microtime(true) - $dbInitStart) * 1000, 2);

    // ── Query 1: Multi 6-table JOIN + Aggregation ──
    $q1 = timedQuery("
        SELECT
            c.name                              AS category,
            pc.name                             AS parent,
            COUNT(DISTINCT p.id)                AS products,
            COUNT(DISTINCT o.id)                AS orders,
            SUM(oi.quantity * oi.unit_price)    AS revenue,
            ROUND(AVG(r.rating), 2)             AS avg_rating,
            COUNT(DISTINCT r.id)                AS reviews
        FROM bench_categories c
        LEFT JOIN bench_categories pc ON c.parent_id = pc.id
        JOIN  bench_products      p  ON p.category_id = c.id
        JOIN  bench_order_items   oi ON oi.product_id = p.id
        JOIN  bench_orders        o  ON o.id = oi.order_id
        LEFT JOIN bench_reviews   r  ON r.product_id  = p.id
        WHERE c.parent_id IS NOT NULL
        GROUP BY c.id, c.name, pc.name
        ORDER BY revenue DESC
        LIMIT 10
    ", '6-Table JOIN + Aggregation');

    // ── Query 2: CTE + Window RANK() ──
    $q2 = timedQuery("
        WITH product_revenue AS (
            SELECT
                p.id,
                p.name                          AS product,
                c.name                          AS category,
                SUM(oi.quantity * oi.unit_price) AS revenue,
                RANK() OVER (
                    PARTITION BY p.category_id
                    ORDER BY SUM(oi.quantity * oi.unit_price) DESC
                ) AS rnk
            FROM bench_products    p
            JOIN bench_categories  c  ON c.id = p.category_id
            JOIN bench_order_items oi ON oi.product_id = p.id
            GROUP BY p.id, p.name, p.category_id, c.name
        )
        SELECT product, category, revenue, rnk
        FROM product_revenue
        WHERE rnk <= 3
        ORDER BY category, rnk
    ", 'CTE + Window RANK()');

    // ── Query 3: Correlated Subquery (above avg price in category) ──
    $q3 = timedQuery("
        SELECT
            p.name,
            p.price,
            c.name AS category,
            (SELECT ROUND(AVG(p2.price), 2)
             FROM bench_products p2
             WHERE p2.category_id = p.category_id) AS cat_avg_price,
            ROUND(p.price - (SELECT AVG(p3.price)
                             FROM bench_products p3
                             WHERE p3.category_id = p.category_id), 2) AS above_avg_by
        FROM bench_products    p
        JOIN bench_categories  c ON c.id = p.category_id
        WHERE p.price > (
            SELECT AVG(p4.price)
            FROM bench_products p4
            WHERE p4.category_id = p.category_id
        )
        ORDER BY c.id, p.price DESC
        LIMIT 15
    ", 'Correlated Subquery');

    // ── Query 4: Self-JOIN Hierarchy + EXISTS ──
    $q4 = timedQuery("
        SELECT
            COALESCE(parent.name, '(Root)') AS parent_category,
            child.name                       AS category,
            COUNT(p.id)                      AS total_products,
            COALESCE(SUM(p.stock), 0)        AS total_stock,
            EXISTS(
                SELECT 1 FROM bench_products p2
                WHERE p2.category_id = child.id AND p2.is_active = 1
            ) AS has_active_products
        FROM bench_categories child
        LEFT JOIN bench_categories parent ON child.parent_id = parent.id
        LEFT JOIN bench_products   p      ON p.category_id   = child.id
        GROUP BY child.id, child.name, parent.name
        ORDER BY parent_category, child.name
    ", 'Self-JOIN + EXISTS');

    // ── Query 5: UNION ALL — Order status revenue analysis ──
    $q5 = timedQuery("
        SELECT 'delivered' AS status,
               COUNT(*)            AS order_count,
               SUM(total)          AS total_revenue,
               ROUND(AVG(total),2) AS avg_order_value,
               ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM bench_orders), 2) AS pct
        FROM bench_orders WHERE status = 'delivered'
        UNION ALL
        SELECT 'shipped', COUNT(*), SUM(total), ROUND(AVG(total),2),
               ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2)
        FROM bench_orders WHERE status = 'shipped'
        UNION ALL
        SELECT 'processing', COUNT(*), SUM(total), ROUND(AVG(total),2),
               ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2)
        FROM bench_orders WHERE status = 'processing'
        UNION ALL
        SELECT 'pending', COUNT(*), SUM(total), ROUND(AVG(total),2),
               ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2)
        FROM bench_orders WHERE status = 'pending'
        UNION ALL
        SELECT 'cancelled', COUNT(*), SUM(total), ROUND(AVG(total),2),
               ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2)
        FROM bench_orders WHERE status = 'cancelled'
        ORDER BY total_revenue DESC
    ", 'UNION ALL + Subquery %');

    // ── Query 6: GROUP BY + HAVING (two-step: pre-calc avg) ──
    $avgSpend = (float) DB::selectOne("SELECT AVG(sub.ct) AS v FROM (SELECT customer_id, SUM(total) AS ct FROM bench_orders GROUP BY customer_id) sub")->v;
    $q6 = timedQuery("
        SELECT
            cu.name,
            cu.city,
            COUNT(DISTINCT o.id)          AS orders,
            SUM(o.total)                  AS lifetime_value,
            ROUND(AVG(o.total), 2)        AS avg_order,
            COUNT(DISTINCT oi.product_id) AS unique_products
        FROM bench_customers   cu
        JOIN bench_orders      o  ON o.customer_id = cu.id
        JOIN bench_order_items oi ON oi.order_id   = o.id
        GROUP BY cu.id, cu.name, cu.city
        HAVING SUM(o.total) > $avgSpend
        ORDER BY lifetime_value DESC
        LIMIT 10
    ", 'GROUP BY + HAVING (pre-calc avg)');

    $queries = [$q1, $q2, $q3, $q4, $q5, $q6];
    $totalDbMs = array_sum(array_column($queries, 'ms'));

    return view('speedtest', [
        'hostname'    => gethostname(),
        'phpVersion'  => PHP_VERSION,
        'framework'   => 'Laravel ' . app()->version(),
        'loops'       => $loops,
        'cpuTime'     => $cpuTime,
        'totalTime'   => round((microtime(true) - $pageStart) * 1000, 2),
        'memoryPeak'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'dbInitMs'    => $dbInitMs,
        'totalDbMs'   => $totalDbMs,
        'queries'     => $queries,
    ]);
});

// ══════════════════════════════════════════════════════════
//  K8s Console — แสดงสถานะ pods, HPA, autoscaling แบบ realtime
// ══════════════════════════════════════════════════════════
function k8sApi(string $path): mixed
{
    $token = @file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token');
    $ca    = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';
    if (!$token) return null;

    $ch = curl_init("https://kubernetes.default.svc{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "Accept: application/json"],
        CURLOPT_CAINFO         => $ca,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

Route::get('/k8s', fn() => view('k8s-console'));

Route::get('/k8s/api', function () {
    $ns = 'oway';

    // Pods
    $podsRaw  = k8sApi("/api/v1/namespaces/{$ns}/pods") ?? ['items' => []];
    // Pod metrics
    $metricsRaw = k8sApi("/apis/metrics.k8s.io/v1beta1/namespaces/{$ns}/pods") ?? ['items' => []];
    // HPAs
    $hpaRaw   = k8sApi("/apis/autoscaling/v2/namespaces/{$ns}/horizontalpodautoscalers") ?? ['items' => []];
    // Deployments
    $depRaw   = k8sApi("/apis/apps/v1/namespaces/{$ns}/deployments") ?? ['items' => []];

    // index metrics by pod name
    $metricsByPod = [];
    foreach ($metricsRaw['items'] ?? [] as $m) {
        $metricsByPod[$m['metadata']['name']] = $m['containers'][0]['usage'] ?? [];
    }

    // parse pods
    $pods = [];
    foreach ($podsRaw['items'] ?? [] as $p) {
        $name   = $p['metadata']['name'];
        $app    = $p['metadata']['labels']['app'] ?? '-';
        $phase  = $p['status']['phase'] ?? 'Unknown';
        $ready  = collect($p['status']['containerStatuses'] ?? [])->every(fn($c) => $c['ready'] ?? false);
        $node   = $p['spec']['nodeName'] ?? '-';
        $usage  = $metricsByPod[$name] ?? [];
        // parse cpu: "45m"=45m, "1"=1000m, "123456789n"=nanocores→m
        $cpuRaw = $usage['cpu'] ?? '0';
        if (str_ends_with($cpuRaw, 'n'))      $cpuM = (int)round((int)$cpuRaw / 1_000_000);
        elseif (str_ends_with($cpuRaw, 'm'))  $cpuM = (int)$cpuRaw;
        else                                   $cpuM = (int)$cpuRaw * 1000;
        $memRaw = $usage['memory'] ?? '0';
        $memMi  = str_ends_with($memRaw, 'Ki') ? round((int)$memRaw / 1024) : (int)$memRaw;

        $startTime = $p['status']['startTime'] ?? null;
        $age = $startTime ? round((time() - strtotime($startTime)) / 60) . 'm' : '-';

        $pods[] = compact('name', 'app', 'phase', 'ready', 'node', 'cpuM', 'memMi', 'age');
    }
    usort($pods, fn($a, $b) => strcmp($a['app'], $b['app']));

    // parse HPAs
    $hpas = [];
    foreach ($hpaRaw['items'] ?? [] as $h) {
        $hpas[] = [
            'name'        => $h['metadata']['name'],
            'target'      => $h['spec']['scaleTargetRef']['name'],
            'min'         => $h['spec']['minReplicas'],
            'max'         => $h['spec']['maxReplicas'],
            'current'     => $h['status']['currentReplicas'] ?? 0,
            'desired'     => $h['status']['desiredReplicas'] ?? 0,
            'cpu_current' => collect($h['status']['currentMetrics'] ?? [])
                ->where('type', 'Resource')
                ->where('resource.name', 'cpu')
                ->value('resource.current.averageUtilization') ?? '-',
            'cpu_target'  => collect($h['spec']['metrics'] ?? [])
                ->where('type', 'Resource')
                ->where('resource.name', 'cpu')
                ->value('resource.target.averageUtilization') ?? 50,
        ];
    }

    // parse Deployments
    $deployments = [];
    foreach ($depRaw['items'] ?? [] as $d) {
        $deployments[] = [
            'name'      => $d['metadata']['name'],
            'desired'   => $d['spec']['replicas'] ?? 0,
            'ready'     => $d['status']['readyReplicas'] ?? 0,
            'available' => $d['status']['availableReplicas'] ?? 0,
        ];
    }

    return response()->json([
        'pods'        => $pods,
        'hpas'        => $hpas,
        'deployments' => $deployments,
        'ts'          => now()->format('H:i:s'),
        'hostname'    => gethostname(),
    ]);
});
