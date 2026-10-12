<?php

namespace App\Policies;

use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;

/**
 * Media files belong to the tenant library; edit rights come through a station.
 */
class MediaFilePolicy
{
    /** Member of any station of the file's tenant. */
    public function view(User $user, MediaFile $mediaFile): bool
    {
        return $user->accessibleStations()->where('stations.tenant_id', $mediaFile->tenant_id)->exists();
    }

    /** Editors edit everything, presenters their own uploads. */
    public function update(User $user, MediaFile $mediaFile, Station $station): bool
    {
        if (! $station->canUseMedia($mediaFile)) {
            return false;
        }

        return $user->can('writeMedia', $station)
            || ($mediaFile->uploaded_by === $user->id && $user->can('uploadMedia', $station));
    }
}
