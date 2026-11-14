# Soft deletes

Nothing in Postloom is hard-deleted by the app. Every model uses `SoftDeletes` and every app table has `deleted_at`.

## For every new model or table

- Add `use SoftDeletes;` to the model and `$table->softDeletes();` to its migration.
- Tests check deletes with `assertSoftDeleted`, not `assertModelMissing`.

## Things a soft delete does not do for you

- **Children:** the database `cascadeOnDelete` only fires on a real delete. Soft delete the rows that belong to the deleted one through `App\Services\SoftDeleteCascade`, so nothing points at a deleted parent. Add a method there for a new parent.
- **Unique keys:** a soft-deleted row still holds its unique value. When the same value comes back, restore that row:
    - `withTrashed()->updateOrCreate(...)`, then `restore()`, as `SocialAccountService::saveConnectedAccount()` and `PostService::syncTargets()` do;
    - or look it up `withTrashed()` first, as `MediaService::upload()` does.
- **When there's no row to restore:** if a name may be reused, drop the DB unique key and use `Rule::unique(...)->withoutTrashed()`, as for workspace names.
- **Validation:** `Rule::exists()` doesn't know about soft deletes. Add `->withoutTrashed()`, otherwise a deleted id passes.
- **Raw queries:** `DB::table()` and joins skip the soft-delete scope. Add `whereNull('deleted_at')`.
- **Files:** media files stay on disk after a soft delete, so a restore works. A later purge command removes old rows and their files.

## Not soft deleted

- Laravel's own tables: `cache`, `jobs`, `sessions`, `password_reset_tokens`, `passkeys`.
- The `post_media` link table. Deleted media drops out of `$post->media` through the scope on `Media`.
