---
name: posts
description: Postloom posts and the post editor — use when changing the Posts list, the New / Edit post page, the posts / post_targets / post_media tables, drafts and scheduling, per-account text and settings, the media picker or LinkedIn preview, or platform post rules (PlatformPostRules, LinkedInPostRules, PlatformRulesRegistry, PostValidator). Also for "can't schedule", "text too long", "LinkedIn can't mix media types", "reconnect this account first", "can't disconnect / delete — used by a scheduled post", timezone or wrong scheduled time.
---

# Posts and the post editor

KB: `knowledge-base/posts/README.md`.

## Key files

- `app/Services/Posts/PostService.php`, `PostValidator.php`
- `app/Services/Posts/Rules/` (`PlatformPostRules`, `LinkedInPostRules`, `PlatformRulesRegistry`)
- `app/Http/Requests/Posts/SavePostRequest.php`, `app/Http/Controllers/PostController.php`, `app/Policies/PostPolicy.php`
- `app/Models/Post.php`, `PostTarget.php`, `app/Enums/PostStatus.php`, `PostTargetStatus.php`
- `resources/js/pages/posts/Form.vue`, `Index.vue`, `resources/js/components/posts/*`

## Adding a platform's posting rules

1. Create `app/Services/Posts/Rules/<Platform>PostRules.php` implementing `PlatformPostRules`.
2. Return it from `PlatformRulesRegistry::forProvider()`.
3. `describe()` drives the editor (limit, media hint, settings fields); `settingsRules()` and `mediaErrors()` drive validation. No editor changes needed.

## Debugging

| Symptom                                    | Check                                                                                                                                                                        |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Schedule refused                           | The reasons list at the top of the form = `PostValidator::scheduleErrors()`. Also logged as `Post could not be scheduled` with `reasons`                                     |
| Wrong time shown                           | `posts.scheduled_at` is UTC; compare with `posts.timezone`. The form sends local time + timezone                                                                             |
| Can't edit a post                          | Only draft, scheduled and failed posts are editable (`PostStatus::isEditable()`). Published / partly published = read-only: use Duplicate to edit; log `Post change refused` |
| Can't delete a post                        | Only while it is `publishing` (`PostStatus::isDeletable()`)                                                                                                                  |
| Can't disconnect an account / delete media | It's used by a scheduled post; move the post to drafts or take it out of the post. Media is also kept while a failed post can still retry with it: retry or delete that post |
| "being published right now" on save        | The scheduler took the post a moment before; wait for it to finish                                                                                                           |

Logs: filter `storage/logs/postloom-*.log` by `extra.feature` = `posts`, then by `extra.post_id`.
