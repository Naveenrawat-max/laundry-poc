<?php
/** Office management of areas, customers, buildings, zones and mat types. */
declare(strict_types=1);

namespace Tests\Feature;

use Tests\ApiTestCase;

final class RefdataTest extends ApiTestCase
{
    public function test_office_builds_a_customer_with_a_building_and_a_zone(): void
    {
        $this->loginAs('office');
        $area = $this->ok($this->api('POST', '/areas', ['name' => 'East']))['row'];
        $customer = $this->ok($this->api('POST', '/customers',
            ['name' => 'Acme', 'contact_name' => 'Ann', 'contact_phone' => '06-1']))['row'];
        $building = $this->ok($this->api('POST', "/customers/{$customer['id']}/buildings",
            ['area_id' => $area['id'], 'name' => 'HQ', 'street' => 'Main 1', 'postcode' => '1234 AB', 'city' => 'Town']))['row'];
        $zone = $this->ok($this->api('POST', "/buildings/{$building['id']}/zones", ['name' => 'Lobby']))['row'];

        $this->assertSame($customer['id'], $building['customer_id']);
        $this->assertTrue($zone['is_active']);
        $this->assertSame(['HQ'],
            array_column($this->ok($this->api('GET', "/customers/{$customer['id']}/buildings"))['rows'], 'name'));
        $this->assertSame(['Lobby', 'Main area'],
            array_column($this->ok($this->api('GET', "/buildings/{$building['id']}/zones"))['rows'], 'name'));
    }

    public function test_lists_come_back_sorted_by_name(): void
    {
        $this->loginAs('office');
        $this->assertSame(['Mock North', 'Mock South'], array_column($this->ok($this->api('GET', '/areas'))['rows'], 'name'));
        $this->assertCount(2, $this->ok($this->api('GET', '/customers'))['rows']);
        $this->assertCount(3, $this->ok($this->api('GET', '/item-types'))['rows']);
    }

    public function test_a_name_that_exists_is_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(409, 'duplicate', $this->api('POST', '/areas', ['name' => 'Mock North']));
        $this->assertError(409, 'duplicate', $this->api('POST', '/buildings/1/zones', ['name' => 'Front door']));
    }

    public function test_a_building_needs_an_area_that_exists(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/customers/1/buildings',
            ['area_id' => 999, 'name' => 'X', 'street' => 'Y 1', 'postcode' => '1', 'city' => 'Z']));
    }

    public function test_required_and_too_long_fields_are_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers', []));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers', ['name' => str_repeat('a', 161)]));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/areas', ['name' => '   ']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', '/customers/1', ['is_active' => 'yes']));
        $this->assertError(422, 'invalid_input', $this->api('PATCH', '/customers/1', ['name' => '']));
    }

    public function test_patch_changes_only_what_is_sent(): void
    {
        $this->loginAs('office');
        $row = $this->ok($this->api('PATCH', '/customers/1', ['is_active' => false, 'contact_name' => '']))['row'];
        $this->assertFalse($row['is_active']);
        $this->assertNull($row['contact_name']);
        $this->assertSame('Mock Bakery BV', $row['name']);
        $this->assertSame('000-0000001', $row['contact_phone']);
    }

    public function test_patch_of_an_unknown_row_is_404(): void
    {
        $this->loginAs('office');
        $this->assertError(404, 'not_found', $this->api('PATCH', '/zones/999', ['name' => 'X']));
    }

    public function test_only_the_office_manages_customers(): void
    {
        $this->loginAs('driver');
        $this->assertError(403, 'forbidden', $this->api('GET', '/customers'));
        $this->assertError(403, 'forbidden', $this->api('POST', '/areas', ['name' => 'East']));
    }

    public function test_inventory_reads_mat_types_but_cannot_change_them(): void
    {
        $this->loginAs('inventory');
        $this->assertCount(3, $this->ok($this->api('GET', '/item-types'))['rows']);
        $this->assertError(403, 'forbidden', $this->api('POST', '/item-types', ['name' => 'New mat']));
    }
}
