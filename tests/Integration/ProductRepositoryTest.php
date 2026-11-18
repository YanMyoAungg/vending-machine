<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Models\Product;
use App\Repositories\PdoProductRepository;
use RuntimeException;

final class ProductRepositoryTest extends IntegrationTestCase
{
    private PdoProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new PdoProductRepository($this->db);
    }

    public function test_paginates_sorts_and_searches(): void
    {
        $this->seedProducts();

        $page = $this->repo->paginate([
            'page' => 1,
            'per_page' => 2,
            'sort' => 'price',
            'direction' => 'desc',
        ]);

        self::assertSame(['Pepsi', 'Coke'], array_map(static fn (Product $product): string => $product->name, $page->items));
        self::assertSame(3, $page->total);
        self::assertTrue($page->hasNext());
        self::assertSame(2, $page->lastPage());
        self::assertSame(1, $this->repo->paginate(['search' => 'water'])->total);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->seedProducts();

        self::assertSame(0, $this->repo->paginate(['search' => '%'])->total);
        self::assertSame(0, $this->repo->paginate(['search' => '_'])->total);
    }

    public function test_hostile_sort_and_page_values_are_clamped(): void
    {
        $this->seedProducts();

        $page = $this->repo->paginate([
            'sort' => 'price; DROP TABLE users',
            'direction' => 'sideways',
            'page' => -4,
            'per_page' => 9999,
        ]);

        self::assertSame(1, $page->page);
        self::assertSame(50, $page->perPage);
        self::assertSame('Coke', $page->items[0]->name);
        self::assertSame(3, $page->total);
    }

    public function test_page_beyond_the_last_returns_no_items(): void
    {
        $this->seedProducts();

        $page = $this->repo->paginate(['page' => 99, 'per_page' => 2]);

        self::assertSame([], $page->items);
        self::assertSame(3, $page->total);
        self::assertFalse($page->hasNext());
    }

    public function test_huge_page_values_return_empty_instead_of_failing(): void
    {
        $this->seedProducts();

        $page = $this->repo->paginate(['page' => PHP_INT_MAX, 'per_page' => 50]);

        self::assertSame([], $page->items);
        self::assertSame(3, $page->total);
    }

    public function test_soft_delete_hides_from_public_but_not_admin(): void
    {
        $products = $this->seedProducts();
        $coke = $products[0];

        self::assertTrue($coke->isActive);

        $this->repo->softDelete($coke->id);

        self::assertNull($this->repo->findBySlug($coke->slug));
        self::assertFalse($this->repo->findById($coke->id)->isActive);
        self::assertSame(2, $this->repo->paginate([])->total);
        self::assertSame(3, $this->repo->paginate(['include_inactive' => true])->total);

        $this->repo->restore($coke->id);
        self::assertTrue($this->repo->findBySlug($coke->slug)->isActive);
    }

    public function test_creates_and_updates_a_product(): void
    {
        $product = $this->repo->create([
            'name' => 'Juice',
            'slug' => 'juice',
            'description' => 'Orange juice.',
            'price' => '2.500',
            'quantity_available' => 5,
            'is_active' => 1,
        ]);

        self::assertSame('2.500', $product->price);
        self::assertSame(5, $product->quantityAvailable);

        $updated = $this->repo->update($product->id, [
            'name' => 'Juice',
            'slug' => 'juice',
            'description' => null,
            'price' => '2.750',
            'quantity_available' => 7,
            'is_active' => 1,
        ]);

        self::assertSame('2.750', $updated->price);
        self::assertSame(7, $updated->quantityAvailable);
        self::assertNull($updated->description);
    }

    public function test_slug_exists_can_ignore_the_product_being_edited(): void
    {
        $product = $this->seedProducts()[0];

        self::assertTrue($this->repo->slugExists('coke'));
        self::assertTrue($this->repo->slugExists('coke', $product->id + 999));
        self::assertFalse($this->repo->slugExists('coke', $product->id));
        self::assertFalse($this->repo->slugExists('missing'));
    }

    public function test_decrement_stock_respects_the_boundary(): void
    {
        $product = $this->seedProducts()[0];

        $this->repo->decrementStock($product->id, $product->quantityAvailable);

        self::assertSame(0, $this->repo->findById($product->id)->quantityAvailable);

        $this->expectException(RuntimeException::class);

        $this->repo->decrementStock($product->id, 1);
    }

    public function test_find_by_slug_for_update_runs_inside_a_transaction(): void
    {
        $product = $this->seedProducts()[0];
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        $locked = $this->repo->findBySlugForUpdate($product->slug);
        $pdo->rollBack();

        self::assertSame($product->id, $locked->id);
        self::assertFalse($pdo->inTransaction());
    }

    /**
     * @return list<Product>
     */
    private function seedProducts(): array
    {
        return [
            $this->repo->create([
                'name' => 'Coke', 'slug' => 'coke', 'description' => 'Cola',
                'price' => '3.990', 'quantity_available' => 12, 'is_active' => 1,
            ]),
            $this->repo->create([
                'name' => 'Pepsi', 'slug' => 'pepsi', 'description' => 'Cola',
                'price' => '6.885', 'quantity_available' => 8, 'is_active' => 1,
            ]),
            $this->repo->create([
                'name' => 'Water', 'slug' => 'water', 'description' => 'Water',
                'price' => '0.500', 'quantity_available' => 24, 'is_active' => 1,
            ]),
        ];
    }
}
