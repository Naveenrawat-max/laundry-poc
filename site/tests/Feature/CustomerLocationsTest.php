<?php

/** Customer locations own contracts and delivery selection; legacy customer-wide records remain readable. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class CustomerLocationsTest extends ApiTestCase
{
    private function location(string $name = 'Shop'): array
    {
        return ['name' => $name, 'street' => 'Main 12', 'postcode' => '1234 AB', 'city' => 'Town'];
    }

    private function contract(int $customer = 1, int $building = 1, int $zone = 1, int $days = 7): array
    {
        return ['customer_id' => $customer, 'building_id' => $building, 'start_date' => '2026-11-01',
            'swap_interval_days' => $days, 'lines' => [['zone_id' => $zone, 'item_type_id' => 1, 'quantity' => 1]]];
    }

    private function delivery(array $over = []): array
    {
        return $over + ['type' => 'delivery', 'customer_id' => 1, 'building_id' => 1,
            'assigned_to' => $this->users['driver'], 'due_date' => '2026-11-01', 'destination' => 'customer',
            'items' => [['item_id' => $this->item(1)['id'], 'zone_id' => 1]]];
    }

    public function test_customer_creation_saves_contacts_and_locations_with_one_default_zone_each(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/customers', ['name' => 'New customer', 'contact_name' => 'Ann',
            'contact_phone' => '06-123', 'contact_email' => 'ann@example.test',
            'locations' => [$this->location('Shop'), $this->location('Warehouse')]]));
        $this->assertSame(['New customer', 'Ann', '06-123', 'ann@example.test'],
            [$out['row']['name'], $out['row']['contact_name'], $out['row']['contact_phone'], $out['row']['contact_email']]);
        $this->assertCount(2, $out['locations']);
        foreach ($out['locations'] as $location) {
            $this->assertSame($out['row']['id'], $location['customer_id']);
            $this->assertNull($location['area_id']);
            $zones = $this->ok($this->api('GET', "/buildings/{$location['id']}/zones"))['rows'];
            $this->assertSame(['Main area'], array_column($zones, 'name'));
            $this->assertTrue($zones[0]['is_active']);
        }
        $own = $this->ok($this->api('GET', "/customers/{$out['row']['id']}/buildings"))['rows'];
        $this->assertSame(['Shop', 'Warehouse'], array_column($own, 'name'));
        $this->assertSame(['Mock Bakery shop'], array_column($this->ok($this->api('GET', '/customers/1/buildings'))['rows'], 'name'));
    }

    public function test_invalid_second_location_cannot_leave_partial_customer_or_location(): void
    {
        $this->loginAs('office');
        $before = array_map(fn (string $table) => DB::table($table)->count(), ['customers', 'buildings', 'zones']);
        $bad = $this->location('Second');
        unset($bad['city']);
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers',
            ['name' => 'Must roll back', 'locations' => [$this->location(), $bad]]));
        foreach (['customer_id' => 2, 'id' => 1] as $key => $value) {
            $this->assertError(422, 'invalid_input', $this->api('POST', '/customers',
                ['name' => 'Forged nested ownership', 'locations' => [$this->location() + [$key => $value]]]));
        }
        $this->assertError(422, 'invalid_input', $this->api('POST', '/customers', ['name' => 'Empty', 'locations' => []]));
        $this->assertSame($before, array_map(fn (string $table) => DB::table($table)->count(), ['customers', 'buildings', 'zones']));
    }

    public function test_legacy_basic_customer_creation_and_later_location_edits_still_work(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/customers', ['name' => 'Basic customer']));
        $this->assertArrayNotHasKey('locations', $out);
        $id = $out['row']['id'];
        $this->assertSame([], $this->ok($this->api('GET', "/customers/$id/buildings"))['rows']);
        $location = $this->ok($this->api('POST', "/customers/$id/buildings", $this->location()))['row'];
        $this->assertNull($location['area_id']);
        $location = $this->ok($this->api('PATCH', "/buildings/{$location['id']}", ['name' => 'Renamed', 'area_id' => 1]))['row'];
        $location = $this->ok($this->api('PATCH', "/buildings/{$location['id']}", ['street' => 'Changed 3', 'is_active' => false]))['row'];
        $this->assertSame(['Renamed', 'Changed 3', 1, false, $id],
            [$location['name'], $location['street'], $location['area_id'], $location['is_active'], $location['customer_id']]);
        $location = $this->ok($this->api('PATCH', "/buildings/{$location['id']}", ['area_id' => null, 'is_active' => true]))['row'];
        $this->assertNull($location['area_id']);
        $this->assertTrue($location['is_active']);
        $this->assertError(422, 'invalid_input', $this->api('PATCH', "/buildings/{$location['id']}", ['street' => '']));
        $this->assertSame(['Main area'], array_column($this->ok($this->api('GET', "/buildings/{$location['id']}/zones"))['rows'], 'name'));
    }

    public function test_new_contract_requires_owned_active_location_and_lines_from_that_location(): void
    {
        $this->loginAs('office');
        $body = $this->contract();
        unset($body['building_id']);
        $this->assertError(422, 'invalid_input', $this->api('POST', '/contracts', $body));
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->contract(building: 2)));
        $second = $this->ok($this->api('POST', '/customers/1/buildings', $this->location('Second')))['row'];
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->contract(building: $second['id'])));
        DB::table('buildings')->where('id', 1)->update(['is_active' => false]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->contract()));
        DB::table('buildings')->where('id', 1)->update(['is_active' => true]);
        DB::table('customers')->where('id', 1)->update(['is_active' => false]);
        $this->assertError(422, 'invalid_reference', $this->api('POST', '/contracts', $this->contract()));
        $this->assertSame(2, DB::table('contracts')->count());
    }

    public function test_draft_location_change_requires_replacement_lines_and_active_location_is_fixed(): void
    {
        $this->loginAs('office');
        $second = $this->ok($this->api('POST', '/customers/1/buildings', $this->location('Second')))['row'];
        $zone = $this->ok($this->api('GET', "/buildings/{$second['id']}/zones"))['rows'][0]['id'];
        $contract = $this->ok($this->api('POST', '/contracts', $this->contract()))['contract'];
        $id = $contract['id'];
        $this->assertError(422, 'invalid_reference', $this->api('PATCH', "/contracts/$id", ['building_id' => $second['id']]));
        $this->assertSame(1, (int) DB::table('contracts')->where('id', $id)->value('building_id'));
        $moved = $this->ok($this->api('PATCH', "/contracts/$id", ['building_id' => $second['id'],
            'lines' => [['zone_id' => $zone, 'item_type_id' => 1, 'quantity' => 1]]]))['contract'];
        $this->assertSame([$second['id'], $zone], [$moved['building_id'], $moved['lines'][0]['zone_id']]);
        $this->ok($this->api('POST', "/contracts/$id/activate"));
        $this->assertError(409, 'not_draft', $this->api('PATCH', "/contracts/$id", ['building_id' => 1]));
    }

    public function test_explicit_contract_activation_rejects_active_legacy_overlap(): void
    {
        $this->activateContract(1);
        $contract = $this->ok($this->api('POST', '/contracts', $this->contract()))['contract'];
        $this->assertError(409, 'active_contract', $this->api('POST', "/contracts/{$contract['id']}/activate"));
        $this->assertSame('draft', DB::table('contracts')->where('id', $contract['id'])->value('status'));
        $this->assertSame(0, DB::table('tasks')->where('contract_id', $contract['id'])->count());
    }

    public function test_two_locations_have_independent_contract_intervals_and_scheduled_dates(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/customers', ['name' => 'Two sites',
            'locations' => [$this->location('First'), $this->location('Second')]]));
        $ids = [];
        foreach ($out['locations'] as $index => $location) {
            $zone = $this->ok($this->api('GET', "/buildings/{$location['id']}/zones"))['rows'][0]['id'];
            $body = $this->contract($out['row']['id'], $location['id'], $zone, [7, 21][$index]);
            $contract = $this->ok($this->api('POST', '/contracts', $body))['contract'];
            $ids[] = $contract['id'];
            $this->ok($this->api('POST', "/contracts/{$contract['id']}/activate"));
            $overlap = $this->ok($this->api('POST', '/contracts', $body))['contract'];
            $this->assertError(409, 'active_contract', $this->api('POST', "/contracts/{$overlap['id']}/activate"));
            DB::table('tasks')->insert(['type' => 'swap', 'contract_id' => $contract['id'],
                'building_id' => $location['id'], 'due_date' => ['2026-11-08', '2026-11-22'][$index]]);
            $rows = $this->ok($this->api('GET', "/contracts?customer_id={$out['row']['id']}&building_id={$location['id']}&status=active"))['rows'];
            $this->assertCount(1, $rows);
            $this->assertSame([$contract['id'], $location['id'], $location['street'], [7, 21][$index], '2026-11-01', ['2026-11-08', '2026-11-22'][$index]],
                [$rows[0]['id'], $rows[0]['building_id'], $rows[0]['street'], $rows[0]['swap_interval_days'], $rows[0]['next_visit_date'], $rows[0]['next_swap_date']]);
        }
        $this->assertCount(2, $this->ok($this->api('GET', "/contracts?customer_id={$out['row']['id']}&status=active"))['rows']);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function test_contract_filters_support_legacy_location_scope_and_validate_ownership(): void
    {
        $this->loginAs('office');
        $this->assertSame([2], array_column($this->ok($this->api('GET', '/contracts?customer_id=2&building_id=2&status=draft'))['rows'], 'id'));
        DB::table('tasks')->insert([
            ['type' => 'swap', 'contract_id' => 2, 'building_id' => 2, 'due_date' => '2026-11-12'],
            ['type' => 'swap', 'contract_id' => 2, 'building_id' => 3, 'due_date' => '2026-11-04'],
        ]);
        $row = $this->ok($this->api('GET', '/contracts?building_id=2'))['rows'][0];
        $this->assertNull($row['building_id']);
        $this->assertSame(['2026-11-12', '2026-11-12'], [$row['next_visit_date'], $row['next_swap_date']]);
        foreach (['customer_id=1&building_id=2', 'customer_id=999', 'building_id=999'] as $filter) {
            $this->assertError(422, 'invalid_reference', $this->api('GET', "/contracts?$filter"));
        }
        $this->assertError(422, 'invalid_input', $this->api('GET', '/contracts?status=unknown'));
    }

    public function test_delivery_resolves_contract_without_client_id_and_preserves_qc_and_reservation(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_contract', $this->api('POST', '/orders', $this->delivery()));
        $contract = $this->ok($this->api('POST', '/contracts', $this->contract()))['contract'];
        $this->ok($this->api('POST', "/contracts/{$contract['id']}/activate"));
        $this->assertError(409, 'active_contract', $this->api('POST', '/contracts/1/activate'));
        $this->assertError(422, 'invalid_contract', $this->api('POST', '/orders', $this->delivery(['contract_id' => 2])));
        $this->assertError(422, 'contract_mismatch', $this->api('POST', '/orders', $this->delivery([
            'items' => [['item_id' => $this->item(1)['id'], 'zone_id' => 1], ['item_id' => $this->item(2)['id'], 'zone_id' => 1]]])));
        $order = $this->ok($this->api('POST', '/orders', $this->delivery()))['order'];
        $this->assertSame([$contract['id'], '2026-11-01', 7, null, 'pending_qc', 'pending'],
            [$order['contract_id'], $order['contract_start_date'], $order['swap_interval_days'], $order['next_swap_date'], $order['status'], $order['items'][0]['qc_status']]);
        $this->assertSame($this->item(1)['id'], (int) DB::table('order_items')->where('order_id', $order['id'])->value('reserved_item_id'));
    }

    public function test_ambiguous_legacy_and_explicit_contracts_cannot_choose_arbitrary_delivery_contract(): void
    {
        $this->activateContract(1);
        $id = DB::table('contracts')->insertGetId(['customer_id' => 1, 'building_id' => 1,
            'swap_interval_days' => 7, 'start_date' => '2026-11-01', 'status' => 'active']);
        DB::table('contract_lines')->insert(['contract_id' => $id, 'zone_id' => 1, 'item_type_id' => 1, 'quantity' => 1]);
        $this->assertError(409, 'ambiguous_contract', $this->api('POST', '/orders', $this->delivery(['contract_id' => $id])));
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());
    }

    public function test_tasks_without_legacy_area_remain_visible_to_office_and_assigned_driver(): void
    {
        DB::table('buildings')->where('id', 1)->update(['area_id' => null]);
        [$id] = $this->activateContract(1, 'driver');
        foreach (['office', 'driver'] as $role) {
            $this->loginAs($role);
            $tasks = $this->ok($this->api('GET', '/tasks'))['tasks'];
            $this->assertSame([$id], array_column($tasks, 'id'));
            $this->assertNull($tasks[0]['area_name']);
            $this->assertSame('Mockstraat 1', $tasks[0]['street']);
        }
    }

    public function test_non_office_roles_cannot_create_customer_locations_or_read_contracts(): void
    {
        foreach (['inventory', 'driver', 'washing', 'outbound'] as $role) {
            $this->loginAs($role);
            $this->assertError(403, 'forbidden', $this->api('POST', '/customers', ['name' => 'Forbidden', 'locations' => [$this->location()]]));
            $this->assertError(403, 'forbidden', $this->api('POST', '/customers/1/buildings', $this->location()));
            $this->assertError(403, 'forbidden', $this->api('GET', '/contracts?customer_id=1&building_id=1'));
        }
    }
}
