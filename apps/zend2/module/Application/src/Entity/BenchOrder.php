<?php

declare(strict_types=1);

namespace Application\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_orders')]
class BenchOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: BenchCustomer::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false)]
    private BenchCustomer $customer;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTime $createdAt;

    #[ORM\OneToMany(targetEntity: BenchOrderItem::class, mappedBy: 'order')]
    private Collection $orderItems;

    public function __construct()
    {
        $this->createdAt  = new DateTime();
        $this->orderItems = new ArrayCollection();
    }

    public function getId(): int              { return $this->id; }
    public function getStatus(): string       { return $this->status; }
    public function getTotal(): string        { return $this->total; }
    public function getCustomer(): BenchCustomer { return $this->customer; }
    public function getOrderItems(): Collection  { return $this->orderItems; }
}
