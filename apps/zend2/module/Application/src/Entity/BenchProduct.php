<?php

declare(strict_types=1);

namespace Application\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_products')]
class BenchProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: BenchCategory::class, inversedBy: 'products')]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    private BenchCategory $category;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 60, unique: true)]
    private string $sku;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $cost;

    #[ORM\Column]
    private int $stock = 0;

    #[ORM\Column(name: 'is_active')]
    private int $isActive = 1;

    #[ORM\OneToMany(targetEntity: BenchOrderItem::class, mappedBy: 'product')]
    private Collection $orderItems;

    #[ORM\OneToMany(targetEntity: BenchReview::class, mappedBy: 'product')]
    private Collection $reviews;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->reviews    = new ArrayCollection();
    }

    public function getId(): int              { return $this->id; }
    public function getName(): string         { return $this->name; }
    public function getSku(): string          { return $this->sku; }
    public function getPrice(): string        { return $this->price; }
    public function getStock(): int           { return $this->stock; }
    public function getIsActive(): int        { return $this->isActive; }
    public function getCategory(): BenchCategory { return $this->category; }
    public function getOrderItems(): Collection  { return $this->orderItems; }
    public function getReviews(): Collection     { return $this->reviews; }
}
