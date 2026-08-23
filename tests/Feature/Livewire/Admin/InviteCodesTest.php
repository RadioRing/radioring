<?php

use App\Livewire\Admin\InviteCodes;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('an admin can generate invite codes', function () {
    Livewire::test(InviteCodes::class)
        ->set('count', 3)
        ->set('note', 'Crew')
        ->call('generate')
        ->assertHasNoErrors();

    expect(InviteCode::count())->toBe(3);
    expect(InviteCode::where('note', 'Crew')->where('created_by', $this->admin->id)->count())->toBe(3);
});

test('generating resets the form back to a single code', function () {
    Livewire::test(InviteCodes::class)
        ->set('count', 5)
        ->set('note', 'X')
        ->call('generate')
        ->assertSet('count', 1)
        ->assertSet('note', '');
});

test('the count is validated', function () {
    Livewire::test(InviteCodes::class)
        ->set('count', 0)
        ->call('generate')
        ->assertHasErrors('count');

    expect(InviteCode::count())->toBe(0);
});

test('an unused code can be deleted', function () {
    $code = InviteCode::factory()->create();

    Livewire::test(InviteCodes::class)
        ->call('deleteCode', $code->id);

    expect(InviteCode::find($code->id))->toBeNull();
});

test('a used code cannot be deleted', function () {
    $code = InviteCode::factory()->used()->create();

    Livewire::test(InviteCodes::class)
        ->call('deleteCode', $code->id);

    expect(InviteCode::find($code->id))->not->toBeNull();
});
