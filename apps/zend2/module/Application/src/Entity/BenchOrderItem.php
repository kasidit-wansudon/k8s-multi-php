<?php

declare(strict_types=1);

namespace Application\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_order_items')]
class BenchOrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: BenchOrder::class, inversedBy: 'orderItems')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private BenchOrder $order;

    #[ORM\ManyToOne(targetEntity: BenchProduct::class, inversedBy: 'orderItems')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private BenchProduct $product;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 10, scale: 2)]
    private string $unitPrice;

    public function getId(): int           { return $this->id; }
    public function getQuantity(): int     { return $this->quantity; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function getOrder(): BenchOrder     { return $this->order; }
    public function getProduct(): BenchProduct { return $this->product; }
}
