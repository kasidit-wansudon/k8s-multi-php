<?php

declare(strict_types=1);

namespace Application\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_categories')]
class BenchCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?self $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\OneToMany(targetEntity: BenchProduct::class, mappedBy: 'category')]
    private Collection $products;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

    public function getId(): int            { return $this->id; }
    public function getName(): string       { return $this->name; }
    public function getSlug(): string       { return $this->slug; }
    public function getParent(): ?self      { return $this->parent; }
    public function getChildren(): Collection { return $this->children; }
    public function getProducts(): Collection { return $this->products; }
}
