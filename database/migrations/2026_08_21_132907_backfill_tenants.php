<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Creates one tenant per user and repoints stations, media files and tags at it.
     *
     * Safe by construction: every station has exactly one owner today (stations.user_id),
     * so "tenant per user" reproduces the current ownership graph exactly. Files that were
     * shared through station_media_links already belong to the same owner, which is why
     * that table needs no special handling: the link becomes implicit.
     */
    public function up(): void
    {
        $this->createTenantPerUser();
        $this->assignStations();
        $this->assignMediaFiles();
        $this->assignTags();
    }

    /**
     * One tenant per user, carrying over the per-user station quota.
     */
    private function createTenantPerUser(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($now) {
            foreach ($users as $user) {
                $tenantId = DB::table('tenants')->insertGetId([
                    'name' => $user->name ?: 'Tenant #'.$user->id,
                    'station_quota' => $user->station_quota ?? 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
            }
        });
    }

    private function assignStations(): void
    {
        DB::table('stations')->orderBy('id')->chunkById(200, function ($stations) {
            foreach ($stations as $station) {
                $tenantId = DB::table('users')->where('id', $station->user_id)->value('tenant_id');

                if ($tenantId) {
                    DB::table('stations')->where('id', $station->id)->update(['tenant_id' => $tenantId]);
                }
            }
        });
    }

    private function assignMediaFiles(): void
    {
        DB::table('media_files')->orderBy('id')->chunkById(500, function ($files) {
            foreach ($files as $file) {
                $tenantId = DB::table('stations')->where('id', $file->station_id)->value('tenant_id');

                if ($tenantId) {
                    DB::table('media_files')->where('id', $file->id)->update(['tenant_id' => $tenantId]);
                }
            }
        });
    }

    /**
     * Tags move from station to tenant scope. Several stations of one tenant may carry
     * the same tag name, so duplicates are merged: the lowest id wins, media_file_tags
     * rows are repointed at it, and the surplus tags are removed. Without this the
     * unique(tenant_id, name) index in the next migration would fail.
     */
    private function assignTags(): void
    {
        $tags = DB::table('tags')
            ->join('stations', 'tags.station_id', '=', 'stations.id')
            ->select('tags.id', 'tags.name', 'stations.tenant_id')
            ->orderBy('tags.id')
            ->get();

        /** @var array<string, int> $canonical keyed by "tenantId|name" */
        $canonical = [];

        foreach ($tags as $tag) {
            if ($tag->tenant_id === null) {
                continue;
            }

            $key = $tag->tenant_id.'|'.mb_strtolower($tag->name);

            if (! isset($canonical[$key])) {
                $canonical[$key] = $tag->id;
                DB::table('tags')->where('id', $tag->id)->update(['tenant_id' => $tag->tenant_id]);

                continue;
            }

            $this->mergeTagInto($tag->id, $canonical[$key]);
        }
    }

    /**
     * Repoints every media_file_tags row from the duplicate onto the canonical tag,
     * skipping rows that would violate the composite primary key, then drops the duplicate.
     */
    private function mergeTagInto(int $duplicateId, int $canonicalId): void
    {
        $mediaFileIds = DB::table('media_file_tags')
            ->where('tag_id', $duplicateId)
            ->pluck('media_file_id');

        foreach ($mediaFileIds as $mediaFileId) {
            $exists = DB::table('media_file_tags')
                ->where('tag_id', $canonicalId)
                ->where('media_file_id', $mediaFileId)
                ->exists();

            if ($exists) {
                DB::table('media_file_tags')
                    ->where('tag_id', $duplicateId)
                    ->where('media_file_id', $mediaFileId)
                    ->delete();

                continue;
            }

            DB::table('media_file_tags')
                ->where('tag_id', $duplicateId)
                ->where('media_file_id', $mediaFileId)
                ->update(['tag_id' => $canonicalId]);
        }

        DB::table('tags')->where('id', $duplicateId)->delete();
    }

    public function down(): void
    {
        DB::table('media_files')->update(['tenant_id' => null]);
        DB::table('tags')->update(['tenant_id' => null]);
        DB::table('stations')->update(['tenant_id' => null]);
        DB::table('users')->update(['tenant_id' => null]);
        DB::table('tenants')->delete();
    }
};
