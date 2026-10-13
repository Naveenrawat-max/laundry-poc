<?php
/** The bigger demo data of db/demo.sql: it loads on top of the mock data, and the API works on it as on the small set. */
declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class DemoDataTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);   // inside the test's transaction, so it is gone again after the test
    }

    private function number(string $sql): int
    {
        return (int) DB::scalar($sql);
    }

    public function test_the_demo_data_adds_200_mats_and_ten_sites(): void
    {
        $this->assertSame(240, $this->number('SELECT COUNT(*) FROM items'));
        $this->assertSame(7, $this->number('SELECT COUNT(*) FROM customers'));
        $this->assertSame(13, $this->number('SELECT COUNT(*) FROM buildings'));
        $this->assertSame(5, $this->number('SELECT COUNT(*) FROM item_types'));
        $this->assertSame(7, $this->number("SELECT COUNT(*) FROM contracts WHERE status = 'draft'"));

        // Every mat is a mock mat, so the whole set can be removed before real mats are registered.
        $this->assertSame(0, $this->number("SELECT COUNT(*) FROM items WHERE epc NOT LIKE '4D4F434B%' OR sku NOT LIKE 'MOCK-%'"));
        $this->assertSame(240, $this->number("SELECT COUNT(*) FROM items WHERE status = 'ready'"));

        // The data hangs together: no site without a spot for a mat, no customer without a contract,
        // and no contract that puts a mat in another customer's building.
        $this->assertSame(0, $this->number('SELECT COUNT(*) FROM buildings b WHERE NOT EXISTS (SELECT 1 FROM zones z WHERE z.building_id = b.id)'));
        $this->assertSame(0, $this->number('SELECT COUNT(*) FROM customers c WHERE NOT EXISTS (SELECT 1 FROM contracts k WHERE k.customer_id = c.id)'));
        $this->assertSame(0, $this->number('SELECT COUNT(*) FROM contract_lines l
            JOIN contracts k ON k.id = l.contract_id JOIN zones z ON z.id = l.zone_id JOIN buildings b ON b.id = z.building_id
            WHERE b.customer_id <> k.customer_id'));
        // The contracts together ask for fewer mats of each type than there are, so every delivery can be loaded.
        $this->assertSame(0, $this->number('SELECT COUNT(*) FROM (SELECT l.item_type_id, SUM(l.quantity) AS wanted FROM contract_lines l GROUP BY l.item_type_id) w
            WHERE w.wanted > (SELECT COUNT(*) FROM items i WHERE i.item_type_id = w.item_type_id)'));
    }

    public function test_the_office_pages_through_all_mats_and_the_counts_add_up(): void
    {
        $this->loginAs('office');
        $first = $this->ok($this->api('GET', '/items'));
        $this->assertSame([240, 50, 50], [$first['total'], $first['per_page'], count($first['items'])]);
        $this->assertCount(40, $this->ok($this->api('GET', '/items?page=5'))['items']);
        $this->assertSame([], $this->ok($this->api('GET', '/items?page=6'))['items']);
        $this->assertSame(1, $this->ok($this->api('GET', '/items?q=MOCK-0240'))['total']);
        $this->assertEquals(['ready' => 240], $this->ok($this->api('GET', '/items/counts'))['counts']);
    }

    public function test_a_contract_with_three_sites_starts_and_a_pile_of_25_goes_to_the_driver(): void
    {
        // Contract 7 is the supermarket chain: three shops in three areas.
        $taskIds = $this->activateContract(7, 'driver');
        $this->assertCount(3, $taskIds);

        $this->loginAs('inventory');
        $needs = $this->ok($this->api('GET', '/tasks'))['drivers'][0];
        $this->assertSame('Driver', $needs['driver']['name']);
        $wanted = $this->number('SELECT SUM(quantity) FROM contract_lines WHERE contract_id = 7');
        $this->assertSame($wanted, array_sum(array_column($needs['needs'], 'needed')));

        // One pile of 25 mats, read in one go on each side.
        $pile = range(41, 65);
        $this->setItems($pile, 'qc_passed');
        $handover = $this->openHandover('inventory', 'inventory_to_driver', 'driver');
        $this->assertCount(25, $this->scanHandover('inventory', $handover, ...$pile)['results']);
        $this->assertCount(25, $this->scanHandover('driver', $handover, ...$pile)['results']);
        $this->ok($this->api('POST', "/handovers/$handover/close"));
        $this->assertSame(25, $this->number("SELECT COUNT(*) FROM items WHERE status = 'out_for_delivery' AND holder_user_id = {$this->users['driver']}"));
    }
}
