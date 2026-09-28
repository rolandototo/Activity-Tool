# Activity Tool

A WordPress activity log plugin. It records important events on the site (content, media, users and plugins) and lists them on a read-only admin screen that only administrators can see.

![Version](https://img.shields.io/badge/version-1.2.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)

## What it logs

| Area | Events | WordPress hook |
|------|--------|----------------|
| Posts, pages and custom post types | Created (as draft, pending, etc.), published, updated (saving a published post), trashed, restored, other status changes | `transition_post_status` |
| | Permanently deleted, from the trash or directly | `before_delete_post` |
| Media | Added, modified, deleted | `add_attachment`, `edit_attachment`, `delete_attachment` |
| Users | Registered, profile updated, deleted | `user_register`, `profile_update`, `delete_user`, `wpmu_delete_user` (multisite) |
| Plugins | Activated, deactivated | `activated_plugin`, `deactivated_plugin` |

- Every entry records the logged-in user who did it. On self-registration, the new user is recorded.
- Bulk actions (such as "Move to Trash" on several posts) log one entry per post.
- By default only post types with an admin UI are logged. Revisions, auto-drafts, menu items and other internal types are skipped, and saving a draft without changing its status isn't logged. The `activity_tool_track_post` filter changes this (see [Filters](#filters)).

## Admin screen

- A new **Activity** menu (clock icon) lists all entries with these columns: **Title** (the event), **ID** (links to the edit screen of the affected post, attachment or user, if it still exists), **User** (login name) and **Date**.
- By default only administrators (`manage_options`) can see the log or delete entries. The `activity_tool_capability` filter changes this (see [Filters](#filters)).
- Entries are read-only:
  - They can't be created or published by hand.
  - The Publish box, Quick Edit and Bulk Edit are removed.
  - The "Edit" link is renamed **View** and opens an **Activity Details** box.

## How data is stored

Each entry is a post of the non-public `activity` post type:

| Field | Content |
|-------|---------|
| Post title | The event message, e.g. `Hello world was published` |
| `_activity_user_id` (meta) | ID of the user who did it (0 for cron jobs) |
| `_modified_post_id` (meta) | ID of the affected post or attachment |
| `_modified_user_id` (meta) | ID of the affected user, for user events |

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Download this repository as a ZIP (**Code > Download ZIP**).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, upload the ZIP and activate **Activity Tool**.

Or clone it into `wp-content/plugins/`. Logging starts as soon as the plugin is active; there's no settings screen.

## Filters

Add these in a small plugin, an mu-plugin or the theme's `functions.php`.

| Filter | Default | Use |
|--------|---------|-----|
| `activity_tool_capability` | `'manage_options'` | Capability needed to see and delete log entries |
| `activity_tool_track_post` | `true` for post types with an admin UI | Whether status changes and deletes of a post are logged. Receives `$tracked` and the `WP_Post` |

```php
// Let editors see the log. The same capability can delete entries.
add_filter('activity_tool_capability', function () {
    return 'edit_others_posts';
});

// Don't log a busy custom post type.
add_filter('activity_tool_track_post', function ($tracked, $post) {
    return 'my_post_type' === $post->post_type ? false : $tracked;
}, 10, 2);
```

On multisite, return `'manage_network_options'` from `activity_tool_capability` to limit the log to super admins.

## Limitations

- Old entries aren't cleaned up automatically.
- Messages are stored in the site language at the time of the event.
- Administrators can delete entries, so it isn't a tamper-proof audit log.
- On multisite, each site has its own log. Network-level events (users added or deleted and plugins activated from Network Admin) are logged on the main site, and removing a user from a single site isn't logged.

## Changelog

### 1.2.0

- New `activity_tool_capability` filter to choose who can see and delete log entries. The default is still `manage_options`.
- New `activity_tool_track_post` filter to skip post types or single posts.
- Multisite: deleting a user from Network Admin is now logged.

### 1.1.0

- Security: all output in the list and the details box is escaped.
- The log is visible only to administrators. Before, editors could read and delete entries.
- Fixed duplicate entries for bulk trash and for restores, the missing log for a second trash, and the missing log for permanent deletes.
- User events record who did them, and the ID column links to the user's profile.
- Internal post types (menu items, revisions) and repeated draft saves are no longer logged.
- Every string is translatable under the `activity-tool` text domain. The license header now matches the GPL-3.0 LICENSE file.
- Removed an unused export method.

Entries created by 1.0 for profile updates stored the user ID as a post ID, so their ID column may link to the wrong item.

### 1.0

- First version.

## File structure

```text
AtivityPlugin.php   The whole plugin (the file name is kept so existing installs stay active)
LICENSE             GNU GPL v3
README.md           This file
```

## Author

**Rolando Escobar**, WordPress developer. [rolandowp.com](https://rolandowp.com)

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
