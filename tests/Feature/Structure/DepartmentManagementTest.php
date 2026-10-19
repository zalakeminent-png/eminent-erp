<?php

namespace Tests\Feature\Structure;

use App\Models\Department;
use App\Models\StructureType;
use App\Models\User;

class DepartmentManagementTest extends StructureTestCase
{
    public function test_admin_can_create_a_department_with_type_and_leaders(): void
    {
        $factory = $this->makeFactory();
        $manager = User::factory()->create();
        $type = StructureType::where('category', 'department')->where('name', 'Casting')->sole();

        $this->actingAs($this->admin)->postJson($this->api('departments'), [
            'factory_id' => $factory->id, 'type_id' => $type->id, 'code' => 'cast', 'name' => 'Casting Shop',
            'manager_id' => $manager->id,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'CAST')
            ->assertJsonPath('data.type.name', 'Casting')
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.supervisor', null);

        $this->assertDatabaseHas('activity_logs', ['action' => 'department.created']);
    }

    public function test_department_cannot_reference_an_invalid_factory(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('departments'), ['factory_id' => 99999, 'code' => 'X', 'name' => 'X'])
            ->assertJsonValidationErrors('factory_id');

        $inactive = $this->makeFactory(['is_active' => false]);
        $this->actingAs($this->admin)->postJson($this->api('departments'), ['factory_id' => $inactive->id, 'code' => 'X', 'name' => 'X'])
            ->assertJsonValidationErrors('factory_id');

        // Wrong-category type is rejected.
        $factory = $this->makeFactory();
        $warehouseType = StructureType::where('category', 'warehouse')->first();
        $this->actingAs($this->admin)->postJson($this->api('departments'), ['factory_id' => $factory->id, 'type_id' => $warehouseType->id, 'code' => 'X', 'name' => 'X'])
            ->assertJsonValidationErrors('type_id');
    }

    public function test_department_code_is_unique_within_its_factory(): void
    {
        $a = $this->makeFactory();
        $b = $this->makeFactory();
        $this->makeDepartment($a, ['code' => 'STORES']);

        $this->actingAs($this->admin)->postJson($this->api('departments'), ['factory_id' => $a->id, 'code' => 'stores', 'name' => 'Stores'])
            ->assertJsonValidationErrors(['code' => 'already used']);

        $this->actingAs($this->admin)->postJson($this->api('departments'), ['factory_id' => $b->id, 'code' => 'STORES', 'name' => 'Stores'])
            ->assertCreated();
    }

    public function test_department_cannot_move_factory(): void
    {
        $department = $this->makeDepartment($this->makeFactory(), ['code' => 'QC']);
        $other = $this->makeFactory();

        $this->actingAs($this->admin)->putJson($this->api("departments/{$department->id}"), ['factory_id' => $other->id, 'code' => 'QC', 'name' => 'QC'])
            ->assertJsonValidationErrors('factory_id');
    }

    public function test_manager_must_be_an_active_user(): void
    {
        $department = $this->makeDepartment($this->makeFactory());
        $inactive = User::factory()->inactive()->create();
        $active = User::factory()->create();

        $this->actingAs($this->admin)->putJson($this->api("departments/{$department->id}/supervisor"), ['manager_id' => $inactive->id, 'supervisor_id' => null])
            ->assertJsonValidationErrors(['manager_id' => 'active user']);

        $this->actingAs($this->admin)->putJson($this->api("departments/{$department->id}/supervisor"), ['manager_id' => $active->id, 'supervisor_id' => $active->id])
            ->assertOk()->assertJsonPath('data.manager.id', $active->id)->assertJsonPath('data.supervisor.id', $active->id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'department.leaders_changed', 'entity_id' => $department->id]);

        // Leaders cannot be changed through the general update endpoint.
        $this->actingAs($this->admin)->putJson($this->api("departments/{$department->id}"), ['code' => $department->code, 'name' => 'X', 'manager_id' => $active->id])
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_assigning_leaders_requires_permission(): void
    {
        $factory = $this->makeFactory();
        $department = $this->makeDepartment($factory);
        $user = $this->scopedUser($factory, ['departments.view', 'departments.create', 'departments.update']);

        $this->actingAs($user)->putJson($this->api("departments/{$department->id}/supervisor"), ['manager_id' => null, 'supervisor_id' => null])->assertForbidden();
        $this->actingAs($user)->postJson($this->api('departments'), ['factory_id' => $factory->id, 'code' => 'NEW', 'name' => 'New', 'manager_id' => $user->id])
            ->assertForbidden();
    }

    public function test_a_user_can_be_assigned_to_an_authorized_department(): void
    {
        $factory = $this->makeFactory();
        $stores = $this->makeDepartment($factory, ['code' => 'STORES']);
        $qc = $this->makeDepartment($factory, ['code' => 'QC']);
        $worker = User::factory()->create();

        // Via the department: first department becomes primary.
        $this->actingAs($this->admin)->putJson($this->api("departments/{$stores->id}/users"), ['user_ids' => [$worker->id]])
            ->assertOk()->assertJsonPath('data.added', [$worker->id]);
        $worker->refresh();
        $this->assertSame($stores->id, $worker->department_id);
        $this->assertSame($factory->id, $worker->factory_id);

        // A second department becomes an additional assignment.
        $this->actingAs($this->admin)->putJson($this->api("departments/{$qc->id}/users"), ['user_ids' => [$worker->id]])->assertOk();
        $this->assertSame([$qc->id], $worker->fresh()->additionalDepartments->modelKeys());

        $this->actingAs($this->admin)->getJson($this->api("departments/{$qc->id}/users"))
            ->assertOk()->assertJsonPath('data.0.id', $worker->id)->assertJsonPath('meta.primary_user_ids', []);

        // Via the user: explicit primary + additional.
        $this->actingAs($this->admin)->putJson($this->api("users/{$worker->id}/assignments"), [
            'factory_id' => $factory->id, 'primary_department_id' => $qc->id, 'department_ids' => [$stores->id],
        ])->assertOk()
            ->assertJsonPath('data.department.id', $qc->id)
            ->assertJsonPath('data.additional_departments.0.id', $stores->id);

        // Removing from a department clears the link.
        $this->actingAs($this->admin)->putJson($this->api("departments/{$stores->id}/users"), ['user_ids' => []])->assertOk();
        $this->assertSame([], $worker->fresh()->additionalDepartments->modelKeys());

        $this->assertDatabaseHas('activity_logs', ['action' => 'department.users_changed']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.assignments_changed', 'entity_id' => $worker->id]);
    }

    public function test_assignment_rules(): void
    {
        $factory = $this->makeFactory();
        $otherFactory = $this->makeFactory();
        $department = $this->makeDepartment($factory);
        $foreign = $this->makeDepartment($otherFactory);
        $inactiveDept = $this->makeDepartment($factory, ['is_active' => false]);
        $worker = User::factory()->create();

        // Primary department must belong to the selected factory.
        $this->actingAs($this->admin)->putJson($this->api("users/{$worker->id}/assignments"), [
            'factory_id' => $factory->id, 'primary_department_id' => $foreign->id, 'department_ids' => [],
        ])->assertJsonValidationErrors('primary_department_id');

        // Inactive departments cannot receive new assignments.
        $this->actingAs($this->admin)->putJson($this->api("users/{$worker->id}/assignments"), [
            'factory_id' => $factory->id, 'primary_department_id' => $department->id, 'department_ids' => [$inactiveDept->id],
        ])->assertJsonValidationErrors('department_ids');
        $this->actingAs($this->admin)->putJson($this->api("departments/{$inactiveDept->id}/users"), ['user_ids' => [$worker->id]])
            ->assertJsonValidationErrors('user_ids');

        // Inactive users cannot be newly assigned.
        $gone = User::factory()->inactive()->create();
        $this->actingAs($this->admin)->putJson($this->api("departments/{$department->id}/users"), ['user_ids' => [$gone->id]])
            ->assertJsonValidationErrors('user_ids');
    }

    public function test_unauthorized_users_cannot_alter_user_assignments(): void
    {
        $factory = $this->makeFactory();
        $department = $this->makeDepartment($factory);
        $worker = User::factory()->create();

        // Can view departments but lacks departments.assign-users.
        $viewer = $this->scopedUser($factory, ['departments.view', 'users.view']);
        $this->actingAs($viewer)->putJson($this->api("departments/{$department->id}/users"), ['user_ids' => [$worker->id]])->assertForbidden();
        $this->actingAs($viewer)->putJson($this->api("users/{$worker->id}/assignments"), [
            'factory_id' => $factory->id, 'primary_department_id' => $department->id, 'department_ids' => [],
        ])->assertForbidden();

        // A scoped assigner cannot change their own assignment...
        $assigner = $this->scopedUser($factory, ['departments.view', 'departments.assign-users']);
        $other = $this->makeFactory();
        $this->actingAs($assigner)->putJson($this->api("users/{$assigner->id}/assignments"), [
            'factory_id' => $other->id, 'primary_department_id' => null, 'department_ids' => [],
        ])->assertForbidden();
        $this->actingAs($assigner)->putJson($this->api("departments/{$department->id}/users"), ['user_ids' => [$assigner->id]])->assertForbidden();

        // ...nor assign anyone to a factory outside their scope.
        $this->actingAs($assigner)->putJson($this->api("users/{$worker->id}/assignments"), [
            'factory_id' => $other->id, 'primary_department_id' => null, 'department_ids' => [],
        ])->assertJsonValidationErrors('factory_id');

        $this->assertNull($worker->fresh()->department_id);
        $this->assertSame($factory->id, $assigner->fresh()->factory_id);
    }

    public function test_department_deactivation_and_reactivation(): void
    {
        $factory = $this->makeFactory();
        $department = $this->makeDepartment($factory);

        $this->actingAs($this->admin)->patchJson($this->api("departments/{$department->id}/status"), ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $factory->update(['is_active' => false]);
        $this->actingAs($this->admin)->patchJson($this->api("departments/{$department->id}/status"), ['is_active' => true])
            ->assertJsonValidationErrors('is_active');

        $this->assertDatabaseHas('activity_logs', ['action' => 'department.deactivated', 'entity_id' => $department->id]);
        $this->actingAs($this->admin)->deleteJson($this->api("departments/{$department->id}"))->assertStatus(405);
        $this->assertModelExists($department);
    }

    public function test_department_list_search_filter_and_pagination(): void
    {
        $a = $this->makeFactory();
        $b = $this->makeFactory();
        foreach (range(1, 7) as $i) {
            $this->makeDepartment($a, ['code' => "A{$i}", 'name' => "Assembly {$i}"]);
        }
        $this->makeDepartment($b, ['code' => 'PK', 'name' => 'Packing', 'is_active' => false]);

        $this->actingAs($this->admin)->getJson($this->api('departments?per_page=5'))
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 8)->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->admin)->getJson($this->api('departments?search=pack'))->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->api("departments?factory_id={$a->id}"))->assertJsonPath('meta.total', 7);
        $this->actingAs($this->admin)->getJson($this->api('departments?status=inactive'))->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->api('departments?sort=code&direction=desc'))->assertJsonPath('data.0.code', 'PK');
    }

    public function test_department_types_are_configurable(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('structure-types'), ['category' => 'department', 'name' => 'Heat Treatment'])
            ->assertCreated();
        $this->actingAs($this->admin)->postJson($this->api('structure-types'), ['category' => 'department', 'name' => 'Heat Treatment'])
            ->assertJsonValidationErrors('name');

        $this->assertTrue(StructureType::where('name', 'Heat Treatment')->exists());
        $this->assertSame(13, StructureType::where('category', 'department')->where('name', '!=', 'Heat Treatment')->count());

        // No departments were created automatically.
        $this->assertSame(0, Department::count());
    }
}
