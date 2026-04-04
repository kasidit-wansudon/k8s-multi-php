<?php

declare(strict_types=1);

namespace Application\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_reviews')]
class BenchReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: BenchProduct::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private BenchProduct $product;

    #[ORM\ManyToOne(targetEntity: BenchCustomer::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false)]
    private BenchCustomer $customer;

    #[ORM\Column(type: 'smallint')]
    private int $rating;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
    }

    public function getId(): int          { return $this->id; }
    public function getRating(): int      { return $this->rating; }
    public function getBody(): ?string    { return $this->body; }
    public function getProduct(): BenchProduct   { return $this->product; }
    public function getCustomer(): BenchCustomer { return $this->customer; }
}
