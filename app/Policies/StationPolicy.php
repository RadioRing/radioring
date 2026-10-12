<?php

namespace App\Policies;

use App\Enums\StationRole;
use App\Models\Station;
use App\Models\User;

/**
 * Single source of station permissions. Matrix:
 *
 * | ability     | founder | owner | editor | presenter |
 * |-------------|---------|-------|--------|-----------|
 * | view        | x       | x     | x      | x         |
 * | goLive      | x       | x     | x      | x         |
 * | uploadMedia | x       | x     | x      | x         |
 * | program     | x       | x     | x      |           |
 * | writeMedia  | x       | x     | x      |           |
 * | skip        | x       | x     | x      |           |
 * | operate     | x       | x     |        |           |
 * | deleteMedia | x       | x     |        |           |
 * | manage      | x       | x     |        |           |
 * | delete      | x       |       |        |           |
 */
class StationPolicy
{
    public function view(User $user, Station $station): bool
    {
        return $this->role($user, $station) !== null;
    }

    /** See the live ingest credentials. */
    public function goLive(User $user, Station $station): bool
    {
        return $this->view($user, $station);
    }

    /** Upload into the tenant library and edit own uploads. */
    public function uploadMedia(User $user, Station $station): bool
    {
        return $this->view($user, $station);
    }

    /** Playlists, grid, rundowns, external sources. */
    public function program(User $user, Station $station): bool
    {
        return $this->atLeastEditor($user, $station);
    }

    /** Edit and tag any file of the tenant library. */
    public function writeMedia(User $user, Station $station): bool
    {
        return $this->atLeastEditor($user, $station);
    }

    public function skip(User $user, Station $station): bool
    {
        return $this->atLeastEditor($user, $station);
    }

    /** Start, stop and restart the container. */
    public function operate(User $user, Station $station): bool
    {
        return $this->isOwner($user, $station);
    }

    /** Delete or replace files: reaches every station of the tenant. */
    public function deleteMedia(User $user, Station $station): bool
    {
        return $this->isOwner($user, $station);
    }

    /** Settings, team, outputs, emergency loop, S4R token. */
    public function manage(User $user, Station $station): bool
    {
        return $this->isOwner($user, $station);
    }

    public function delete(User $user, Station $station): bool
    {
        return $station->isOwnedBy($user);
    }

    private function role(User $user, Station $station): ?StationRole
    {
        return $station->isOwnedBy($user) ? StationRole::Owner : $user->roleOn($station);
    }

    private function isOwner(User $user, Station $station): bool
    {
        return $this->role($user, $station) === StationRole::Owner;
    }

    private function atLeastEditor(User $user, Station $station): bool
    {
        return in_array($this->role($user, $station), [StationRole::Owner, StationRole::Editor], true);
    }
}
