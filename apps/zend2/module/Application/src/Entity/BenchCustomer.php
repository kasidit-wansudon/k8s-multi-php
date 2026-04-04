<?php

declare(strict_types=1);

namespace Application\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_customers')]
class BenchCustomer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 150, unique: true)]
    private string $email;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2)]
    private string $country = 'TH';

    #[ORM\OneToMany(targetEntity: BenchOrder::class, mappedBy: 'customer')]
    private Collection $orders;

    #[ORM\OneToMany(targetEntity: BenchReview::class, mappedBy: 'customer')]
    private Collection $reviews;

    public function __construct()
    {
        $this->orders  = new ArrayCollection();
        $this->reviews = new ArrayCollection();
    }

    public function getId(): int         { return $this->id; }
    public function getName(): string    { return $this->name; }
    public function getEmail(): string   { return $this->email; }
    public function getCity(): ?string   { return $this->city; }
    public function getOrders(): Collection  { return $this->orders; }
    public function getReviews(): Collection { return $this->reviews; }
}
