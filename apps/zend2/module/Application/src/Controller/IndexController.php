<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Entity\BenchCategory;
use Application\Entity\BenchProduct;
use Application\Entity\BenchCustomer;
use Application\Entity\BenchOrder;
use Application\Entity\BenchOrderItem;
use Application\Entity\BenchReview;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Redis;
use PDO;

class IndexController extends AbstractActionController
{
    // ══════════════════════════════════════════════════════════
    //  Redis — ดึง connection (หรือ null ถ้า Redis ไม่พร้อม)
    // ══════════════════════════════════════════════════════════
    private function getRedis(): ?Redis
    {
        try {
            $r = new Redis();
            $r->connect(getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379), 0.5);
            return $r;
        } catch (\Exception) {
            return null;
        }
    }

    private function cached(string $key, int $ttl, callable $fn): array
    {
        $redis = $this->getRedis();
        if ($redis) {
            $cached = $redis->get($key);
            if ($cached !== false) return json_decode($cached, true);
        }
        $data = $fn();
        if ($redis) $redis->setex($key, $ttl, json_encode($data));
        return $data;
    }

    // ══════════════════════════════════════════════════════════
    //  Bootstrap Doctrine EntityManager
    // ══════════════════════════════════════════════════════════
    private function getEntityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Entity'],
            isDevMode: true,
            proxyDir: sys_get_temp_dir() . '/doctrine_proxies',
        );

        $connection = DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => getenv('DB_HOST') ?: 'mysql',
            'port'     => (int)(getenv('DB_PORT') ?: 3306),
            'dbname'   => getenv('DB_DATABASE') ?: 'mex_sellin',
            'user'     => getenv('MYSQL_USER') ?: 'oway',
            'password' => getenv('MYSQL_PASSWORD') ?: 'oway_secret',
            'charset'  => 'utf8mb4',
        ]);

        return new EntityManager($connection, $config);
    }

    // ══════════════════════════════════════════════════════════
    //  สร้างตาราง + seed (ถ้ายังไม่มี) — ใช้ DBAL โดยตรง
    // ══════════════════════════════════════════════════════════
    private function ensureBenchSchema(EntityManager $em): float
    {
        $t    = microtime(true);
        $conn = $em->getConnection();

        $exists = $conn->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'bench_categories'"
        );

        if ($exists > 0) {
            return round((microtime(true) - $t) * 1000, 2);
        }

        $conn->executeStatement("
            CREATE TABLE bench_categories (
                id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NULL,
                name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL,
                INDEX idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE bench_products (
                id INT AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL,
                name VARCHAR(200) NOT NULL, sku VARCHAR(60) NOT NULL UNIQUE,
                price DECIMAL(10,2) NOT NULL, cost DECIMAL(10,2) NOT NULL,
                stock INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1,
                INDEX idx_cat(category_id), INDEX idx_price(price)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE bench_customers (
                id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL UNIQUE, city VARCHAR(80), country CHAR(2) DEFAULT 'TH',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_country(country)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE bench_orders (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL,
                status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
                total DECIMAL(10,2) DEFAULT 0.00, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer(customer_id), INDEX idx_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE bench_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL,
                product_id INT NOT NULL, quantity INT NOT NULL, unit_price DECIMAL(10,2) NOT NULL,
                INDEX idx_order(order_id), INDEX idx_product(product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE bench_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL,
                customer_id INT NOT NULL, rating TINYINT NOT NULL, body TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product(product_id), INDEX idx_rating(rating)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->seedBench($conn);

        return round((microtime(true) - $t) * 1000, 2);
    }

    private function seedBench(\Doctrine\DBAL\Connection $conn): void
    {
        $parents  = ['Electronics','Clothing','Sports','Food & Drink','Books','Home & Living','Beauty','Toys'];
        $children = [
            ['Mobile Phones','Laptops & Tablets'],['T-Shirts & Tops','Jeans & Pants'],
            ['Running','Swimming & Water Sports'],['Snacks','Beverages'],
            ['Fiction','Non-Fiction & Education'],['Furniture','Kitchen & Dining'],
            ['Skincare','Makeup & Color'],['Action Figures','Educational Toys'],
        ];
        $leafIds = [];
        foreach ($parents as $i => $pname) {
            $conn->executeStatement("INSERT INTO bench_categories (name,slug,parent_id) VALUES (?,?,NULL)", [$pname, strtolower(str_replace(' ','_',$pname))]);
            $pid = $conn->lastInsertId();
            foreach ($children[$i] as $cname) {
                $conn->executeStatement("INSERT INTO bench_categories (name,slug,parent_id) VALUES (?,?,?)", [$cname, strtolower(str_replace([' ','&'],['_',''],$cname)), $pid]);
                $leafIds[] = $conn->lastInsertId();
            }
        }
        $adj = ['Pro','Plus','Ultra','Lite','Max','Mini','Smart','Elite'];
        $mat = ['Carbon','Silver','Gold','Black','White','Blue','Red','Green'];
        $rows = []; $pid = 1;
        foreach ($leafIds as $li => $catId) {
            for ($j = 0; $j < 8; $j++) {
                $price = round(rand(99,9999)+rand(0,99)/100, 2);
                $cost  = round($price*(0.4+rand(0,20)/100), 2);
                $rows[] = "($catId,'".addslashes("{$adj[$j]} {$mat[$li%8]} Product ".($li*8+$j+1))."','SKU-".str_pad((string)$pid,5,'0',STR_PAD_LEFT)."',$price,$cost,".rand(0,999).",1)";
                $pid++;
            }
        }
        $conn->executeStatement("INSERT INTO bench_products (category_id,name,sku,price,cost,stock,is_active) VALUES ".implode(',',$rows));

        $cities = ['Bangkok','Chiang Mai','Phuket','Pattaya','Khon Kaen','Hat Yai','Udon Thani','Nakhon Si Thammarat'];
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $rows[] = "('Customer $i Name','customer$i@bench.local','".$cities[$i%8]."','TH')";
        }
        $conn->executeStatement("INSERT INTO bench_customers (name,email,city,country) VALUES ".implode(',',$rows));

        $statuses = ['pending','processing','shipped','delivered','cancelled'];
        $rows = [];
        for ($i = 1; $i <= 800; $i++) {
            $rows[] = "(". rand(1,300) .",'". $statuses[$i%5] ."',0.00,DATE_SUB(NOW(),INTERVAL ".rand(1,365)." DAY))";
        }
        $conn->executeStatement("INSERT INTO bench_orders (customer_id,status,total,created_at) VALUES ".implode(',',$rows));

        $rows = [];
        for ($ordId = 1; $ordId <= 800; $ordId++) {
            $used = [];
            for ($k = 0; $k < rand(1,5); $k++) {
                do { $prd = rand(1,128); } while (in_array($prd,$used));
                $used[] = $prd;
                $rows[] = "($ordId,$prd,".rand(1,10).",".round(rand(99,9999)+rand(0,99)/100,2).")";
            }
        }
        $conn->executeStatement("INSERT INTO bench_order_items (order_id,product_id,quantity,unit_price) VALUES ".implode(',',$rows));
        $conn->executeStatement("UPDATE bench_orders o JOIN (SELECT order_id,SUM(quantity*unit_price) AS t FROM bench_order_items GROUP BY order_id) s ON o.id=s.order_id SET o.total=s.t");

        $rows = []; $revSet = []; $cnt = 0;
        while ($cnt < 600) {
            $pr = rand(1,128); $cu = rand(1,300); $key = "$pr-$cu";
            if (isset($revSet[$key])) continue;
            $revSet[$key] = true;
            $rating = rand(1,5);
            $rows[] = "($pr,$cu,$rating,'".addslashes("Review rating $rating for product $pr")."')";
            $cnt++;
        }
        $conn->executeStatement("INSERT INTO bench_reviews (product_id,customer_id,rating,body) VALUES ".implode(',',$rows));
    }

    // ══════════════════════════════════════════════════════════
    //  Helper: run + time query
    // ══════════════════════════════════════════════════════════
    private function timed(callable $fn, string $label, string $method, int $ttl = 30): array
    {
        $key    = 'bq:' . md5($label);
        $hit    = true;
        $t      = microtime(true);
        $data   = $this->cached($key, $ttl, function () use ($fn, &$hit) {
            $hit = false;
            return $fn();
        });
        return [
            'label'  => $label,
            'method' => $method,
            'ms'     => round((microtime(true) - $t) * 1000, 2),
            'rows'   => count($data),
            'data'   => $data,
            'cached' => $hit,
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  Main Action
    // ══════════════════════════════════════════════════════════
    public function indexAction(): ViewModel
    {
        $pageStart = microtime(true);

        $loops    = max(1, min((int)($this->getRequest()->getQuery('loops', 100000)), 5000000));
        $cpuStart = microtime(true);
        $x = 0;
        for ($i = 0; $i < $loops; $i++) { $x += sqrt($i) * sin($i); }
        $cpuTime = round((microtime(true) - $cpuStart) * 1000, 2);

        $em       = $this->getEntityManager();
        $dbInitMs = $this->ensureBenchSchema($em);

        // ── Q1: Doctrine QueryBuilder — 6-Table JOIN + Aggregation ──
        $q1 = $this->timed(function () use ($em) {
            $qb = $em->createQueryBuilder()
                ->select(
                    'c.name AS category',
                    'pc.name AS parent',
                    'COUNT(DISTINCT p.id) AS products',
                    'COUNT(DISTINCT o.id) AS orders',
                    'SUM(oi.unitPrice * oi.quantity) AS revenue',
                    'AVG(r.rating) AS avg_rating',
                    'COUNT(DISTINCT r.id) AS reviews'
                )
                ->from(BenchCategory::class, 'c')
                ->leftJoin('c.parent', 'pc')
                ->join('c.products', 'p')
                ->join('p.orderItems', 'oi')
                ->join('oi.order', 'o')
                ->leftJoin('p.reviews', 'r')
                ->where('c.parent IS NOT NULL')
                ->groupBy('c.id, c.name, pc.name')
                ->orderBy('revenue', 'DESC')
                ->setMaxResults(10);
            return $qb->getQuery()->getScalarResult();
        }, '6-Table JOIN + Aggregation', 'Doctrine QueryBuilder');

        // ── Q2: NativeQuery — CTE + Window RANK() (DQL ไม่รองรับ) ──
        $q2 = $this->timed(function () use ($em) {
            $rsm = new ResultSetMappingBuilder($em);
            $rsm->addScalarResult('product',  'product');
            $rsm->addScalarResult('category', 'category');
            $rsm->addScalarResult('revenue',  'revenue');
            $rsm->addScalarResult('rnk',      'rnk');
            return $em->createNativeQuery("
                WITH product_revenue AS (
                    SELECT p.id, p.name AS product, c.name AS category,
                           SUM(oi.quantity * oi.unit_price) AS revenue,
                           RANK() OVER (PARTITION BY p.category_id ORDER BY SUM(oi.quantity*oi.unit_price) DESC) AS rnk
                    FROM bench_products p
                    JOIN bench_categories c  ON c.id = p.category_id
                    JOIN bench_order_items oi ON oi.product_id = p.id
                    GROUP BY p.id, p.name, p.category_id, c.name
                )
                SELECT product, category, revenue, rnk
                FROM product_revenue WHERE rnk <= 3 ORDER BY category, rnk
            ", $rsm)->getResult();
        }, 'CTE + Window RANK()', 'NativeQuery (DQL ไม่รองรับ CTE)');

        // ── Q3: NativeQuery — Correlated Subquery per-category avg ──
        $q3 = $this->timed(function () use ($em) {
            $rsm = new ResultSetMappingBuilder($em);
            $rsm->addScalarResult('name',         'name');
            $rsm->addScalarResult('price',        'price');
            $rsm->addScalarResult('category',     'category');
            $rsm->addScalarResult('above_avg_by', 'above_avg_by');
            return $em->createNativeQuery("
                SELECT p.name, p.price, c.name AS category,
                       ROUND(p.price - (SELECT AVG(p2.price) FROM bench_products p2 WHERE p2.category_id = c.id), 2) AS above_avg_by
                FROM bench_products p
                JOIN bench_categories c ON c.id = p.category_id
                WHERE p.price > (SELECT AVG(p3.price) FROM bench_products p3 WHERE p3.category_id = c.id)
                ORDER BY p.price DESC LIMIT 15
            ", $rsm)->getResult();
        }, 'Correlated Subquery (above-avg price)', 'NativeQuery');

        // ── Q4: DQL — Self-JOIN Category Hierarchy ──
        $q4 = $this->timed(function () use ($em) {
            $dql = "SELECT COALESCE(pc.name, '(Root)') AS parent_category,
                           c.name AS category,
                           COUNT(p.id) AS total_products,
                           COALESCE(SUM(p.stock), 0) AS total_stock
                    FROM " . BenchCategory::class . " c
                    LEFT JOIN c.parent pc
                    LEFT JOIN c.products p
                    GROUP BY c.id, c.name, pc.name
                    ORDER BY parent_category, c.name";
            return $em->createQuery($dql)->getScalarResult();
        }, 'Self-JOIN Hierarchy', 'DQL (Doctrine Query Language)');

        // ── Q5: NativeQuery — UNION ALL ──
        $q5 = $this->timed(function () use ($em) {
            $rsm = new ResultSetMappingBuilder($em);
            $rsm->addScalarResult('status',          'status');
            $rsm->addScalarResult('order_count',     'order_count');
            $rsm->addScalarResult('total_revenue',   'total_revenue');
            $rsm->addScalarResult('avg_order_value', 'avg_order_value');
            $rsm->addScalarResult('pct',             'pct');
            return $em->createNativeQuery("
                SELECT 'delivered' AS status, COUNT(*) AS order_count, SUM(total) AS total_revenue,
                       ROUND(AVG(total),2) AS avg_order_value,
                       ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2) AS pct
                FROM bench_orders WHERE status='delivered'
                UNION ALL
                SELECT 'shipped',COUNT(*),SUM(total),ROUND(AVG(total),2),ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2) FROM bench_orders WHERE status='shipped'
                UNION ALL
                SELECT 'processing',COUNT(*),SUM(total),ROUND(AVG(total),2),ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2) FROM bench_orders WHERE status='processing'
                UNION ALL
                SELECT 'pending',COUNT(*),SUM(total),ROUND(AVG(total),2),ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2) FROM bench_orders WHERE status='pending'
                UNION ALL
                SELECT 'cancelled',COUNT(*),SUM(total),ROUND(AVG(total),2),ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM bench_orders),2) FROM bench_orders WHERE status='cancelled'
                ORDER BY total_revenue DESC
            ", $rsm)->getResult();
        }, 'UNION ALL + Subquery %', 'NativeQuery (DQL ไม่รองรับ UNION)');

        // ── Q6: QueryBuilder — GROUP BY + HAVING nested subquery ──
        $q6 = $this->timed(function () use ($em) {
            // คำนวณ avg customer spend ก่อน แล้วใส่ใน HAVING
            $avgSpend = (float) $em->getConnection()->fetchOne(
                "SELECT AVG(sub.ct) FROM (SELECT customer_id, SUM(total) AS ct FROM bench_orders GROUP BY customer_id) sub"
            );
            $qb = $em->createQueryBuilder()
                ->select(
                    'cu.name', 'cu.city',
                    'COUNT(DISTINCT o.id) AS orders',
                    'SUM(o.total) AS lifetime_value',
                    'AVG(o.total) AS avg_order',
                    'COUNT(DISTINCT oi.product) AS unique_products'
                )
                ->from(BenchCustomer::class, 'cu')
                ->join('cu.orders', 'o')
                ->join('o.orderItems', 'oi')
                ->groupBy('cu.id, cu.name, cu.city')
                ->having('SUM(o.total) > :avg')
                ->setParameter('avg', $avgSpend)
                ->orderBy('lifetime_value', 'DESC')
                ->setMaxResults(10);
            return $qb->getQuery()->getScalarResult();
        }, 'GROUP BY + HAVING Nested Subquery', 'QueryBuilder + DBAL subquery');

        $queries = [$q1, $q2, $q3, $q4, $q5, $q6];

        $view = new ViewModel([
            'hostname'   => gethostname(),
            'phpVersion' => PHP_VERSION,
            'framework'  => 'Laminas MVC 3.8 + Doctrine ORM 3.6',
            'loops'      => $loops,
            'cpuTime'    => $cpuTime,
            'totalTime'  => round((microtime(true) - $pageStart) * 1000, 2),
            'memoryPeak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'dbInitMs'   => $dbInitMs,
            'totalDbMs'  => round(array_sum(array_column($queries, 'ms')), 2),
            'queries'    => $queries,
        ]);

        $view->setTerminal(true);

        return $view;
    }
}
