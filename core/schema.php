<?php
/**
 * Core database schema. schema_install() is idempotent: it creates missing tables,
 * adds missing columns and indexes, and records the schema version.
 * Plugins own their own tables (prefix plugin_<id>_) and must not touch fb_* tables.
 */

const SCHEMA_VERSION = 8; // bump on every change to schema_tables()/schema_indexes(): app_boot() runs schema_install() when the stored version differs

function schema_tables(): array
{
    return [
        'fb_settings' => [
            'key' => 'key',
            'value' => 'mediumtext',
        ],
        'fb_users' => [
            'id' => 'id',
            'username' => 'key',
            'username_lower' => 'key',
            'email' => 'key',
            'password' => 'string',
            'group_id' => 'uint',
            'avatar' => 'string',
            'bio' => 'text',
            'website' => 'string',
            'location' => 'string',
            'signature' => 'text',
            'topic_count' => 'uint',
            'post_count' => 'uint',
            'like_count' => 'uint',
            'points' => 'int',
            'status' => 'bool',          // 0 banned, 1 active
            'last_seen' => 'uint',
            'last_post_at' => 'uint',
            'created_at' => 'uint',
            'created_ip' => 'string',
            'unread_notifications' => 'uint',
            'prefs' => 'text',           // JSON
            'former_names' => 'text',    // JSON list of {name, at}: old profile URLs keep working after a rename
            'auth_salt' => 'string',     // part of the session signature; user_logout_everywhere() rotates it
            'email_verified' => 'uint',  // 1 when the address was confirmed with a code (register_verify)
            'reset_token' => 'string',   // sha256 of the password-reset token
            'reset_expires' => 'uint',
        ],
        'fb_groups' => [
            'id' => 'id',
            'name' => 'string',
            'slug' => 'key',
            'color' => 'string',
            'is_admin' => 'bool',
            'is_mod' => 'bool',
            'permissions' => 'text',     // JSON: ["post","reply","upload","edit_own","delete_own"]
            'sort' => 'int',
        ],
        'fb_categories' => [
            'id' => 'id',
            'parent_id' => 'uint',
            'name' => 'string',
            'slug' => 'key',
            'description' => 'text',
            'color' => 'string',
            'icon' => 'string',
            'sort' => 'int',
            'topic_count' => 'uint',
            'post_count' => 'uint',
            'last_topic_id' => 'uint',
            'view_groups' => 'string',   // comma separated group ids, empty = everyone
            'post_groups' => 'string',   // comma separated group ids, empty = any member
            'is_hidden' => 'bool',
        ],
        'fb_tags' => [
            'id' => 'id',
            'name' => 'key',
            'slug' => 'key',
            'topic_count' => 'uint',
        ],
        'fb_topic_tags' => [
            'topic_id' => 'uint',
            'tag_id' => 'uint',
        ],
        'fb_topics' => [
            'id' => 'id',
            'category_id' => 'uint',
            'user_id' => 'uint',
            'title' => 'string',
            'slug' => 'string',
            'first_post_id' => 'uint',
            'reply_count' => 'uint',
            'view_count' => 'uint',
            'like_count' => 'uint',
            'last_post_id' => 'uint',
            'last_post_at' => 'uint',
            'last_user_id' => 'uint',
            'is_pinned' => 'bool',
            'pinned_at' => 'uint',         // pinned topics sort by this, newest pin first; 0 when not pinned
            'is_locked' => 'bool',
            'is_deleted' => 'bool',
            'hot_score' => 'float',
            'created_at' => 'uint',
            'updated_at' => 'uint',
            'meta' => 'text',            // JSON for plugins
        ],
        'fb_posts' => [
            'id' => 'id',
            'topic_id' => 'uint',
            'user_id' => 'uint',
            'floor' => 'uint',           // 0 = first post
            'reply_to_id' => 'uint',
            'body' => 'mediumtext',      // markdown source
            'body_html' => 'mediumtext', // rendered cache
            'like_count' => 'uint',
            'is_deleted' => 'bool',
            'edit_count' => 'uint',
            'edited_at' => 'uint',
            'edited_by' => 'uint',
            'created_at' => 'uint',
            'created_ip' => 'string',
            'meta' => 'text',
        ],
        'fb_likes' => [
            'id' => 'id',
            'user_id' => 'uint',
            'post_id' => 'uint',
            'topic_id' => 'uint',
            'created_at' => 'uint',
        ],
        'fb_bookmarks' => [
            'id' => 'id',
            'user_id' => 'uint',
            'topic_id' => 'uint',
            'created_at' => 'uint',
        ],
        'fb_topic_reads' => [
            'user_id' => 'uint',
            'topic_id' => 'uint',
            'last_post_id' => 'uint',
            'read_at' => 'uint',
        ],
        'fb_notifications' => [
            'id' => 'id',
            'user_id' => 'uint',         // recipient
            'from_user_id' => 'uint',
            'kind' => 'string',          // reply, mention, like, system
            'topic_id' => 'uint',
            'post_id' => 'uint',
            'content' => 'text',
            'is_read' => 'bool',
            'created_at' => 'uint',
        ],
        'fb_attachments' => [
            'id' => 'id',
            'user_id' => 'uint',
            'post_id' => 'uint',
            'name' => 'string',
            'path' => 'string',
            'mime' => 'string',
            'size' => 'uint',
            'is_image' => 'bool',
            'width' => 'uint',
            'height' => 'uint',
            'hash' => 'string',
            'created_at' => 'uint',
        ],
        'fb_plugins' => [
            'id' => 'key',
            'name' => 'string',
            'version' => 'string',
            'enabled' => 'bool',
            'installed' => 'bool',
            'settings' => 'text',        // JSON
            'manifest' => 'text',        // JSON snapshot (name, description, hooks, ...)
            'sort' => 'int',
            'updated_at' => 'uint',
        ],
        'fb_cron' => [
            'name' => 'key',
            'last_run' => 'uint',
            'last_status' => 'string',
            'run_count' => 'uint',
        ],
        'fb_points_log' => [
            'id' => 'id',
            'user_id' => 'uint',
            'delta' => 'int',
            'reason' => 'key',          // code registered through hook points.reasons
            'ref_id' => 'uint',
            'note' => 'string',
            'created_at' => 'uint',
        ],
        'fb_email_codes' => [
            'id' => 'id',
            'email' => 'key',
            'code_hash' => 'string',
            'ip' => 'string',
            'attempts' => 'uint',
            'sent_at' => 'uint',
            'expires_at' => 'uint',
        ],
        'fb_admin_log' => [
            'id' => 'id',
            'user_id' => 'uint',
            'ip' => 'string',
            'action' => 'key',       // settings, user.save, plugin.enable, ...
            'target' => 'string',
            'detail' => 'text',
            'created_at' => 'uint',
        ],
        'fb_search' => [
            'id' => 'id',
            'topic_id' => 'uint',
            'post_id' => 'uint',
            'title' => 'text',
            'body' => 'mediumtext',
        ],
    ];
}

function schema_indexes(): array
{
    return [
        ['fb_settings', 'ux_settings_key', ['key'], true],
        ['fb_users', 'ux_users_username', ['username_lower'], true],
        ['fb_users', 'ix_users_email', ['email']],
        ['fb_users', 'ix_users_group', ['group_id']],
        ['fb_users', 'ix_users_points', ['points']],
        ['fb_email_codes', 'ux_email_codes', ['email'], true],
        ['fb_admin_log', 'ix_admin_log_time', ['created_at']],
        ['fb_admin_log', 'ix_admin_log_user', ['user_id', 'id']], // leaderboards (the points plugin used to create it)
        ['fb_groups', 'ux_groups_slug', ['slug'], true],
        ['fb_categories', 'ux_categories_slug', ['slug'], true],
        ['fb_tags', 'ux_tags_slug', ['slug'], true],
        ['fb_topic_tags', 'ux_topic_tags', ['topic_id', 'tag_id'], true],
        ['fb_topic_tags', 'ix_topic_tags_tag', ['tag_id', 'topic_id']],
        ['fb_topics', 'ix_topics_latest', ['is_deleted', 'is_pinned', 'last_post_at']],
        ['fb_topics', 'ix_topics_category', ['category_id', 'is_deleted', 'last_post_at']],
        ['fb_topics', 'ix_topics_user', ['user_id', 'created_at']],
        ['fb_topics', 'ix_topics_created', ['created_at']],
        ['fb_topics', 'ix_topics_hot', ['is_deleted', 'hot_score']],
        ['fb_posts', 'ix_posts_topic', ['topic_id', 'is_deleted', 'id']],
        ['fb_posts', 'ix_posts_user', ['user_id', 'created_at']],
        ['fb_likes', 'ux_likes', ['user_id', 'post_id'], true],
        ['fb_likes', 'ix_likes_post', ['post_id']],
        ['fb_bookmarks', 'ux_bookmarks', ['user_id', 'topic_id'], true],
        ['fb_topic_reads', 'ux_topic_reads', ['user_id', 'topic_id'], true],
        ['fb_notifications', 'ix_notifications_user', ['user_id', 'is_read', 'id']],
        ['fb_attachments', 'ix_attachments_post', ['post_id']],
        ['fb_attachments', 'ix_attachments_user', ['user_id', 'id']],
        ['fb_plugins', 'ux_plugins_id', ['id'], true],
        ['fb_cron', 'ux_cron_name', ['name'], true],
        ['fb_points_log', 'ix_points_log_user', ['user_id', 'id']],
        ['fb_search', 'ux_search_post', ['post_id'], true],
        ['fb_search', 'ix_search_topic', ['topic_id']],
    ];
}

/** Create or upgrade all core tables. Safe to run repeatedly. */
function schema_install(): void
{
    foreach (schema_tables() as $table => $columns) db_create_table($table, $columns);
    foreach (schema_indexes() as $ix) db_create_index($ix[0], $ix[1], $ix[2], $ix[3] ?? false);
    db_create_fulltext('fb_search', 'ft_search', ['title', 'body']);
    search_index_install();
    q('UPDATE fb_topics SET pinned_at=created_at WHERE is_pinned=1 AND pinned_at=0'); // topics pinned before pinned_at existed keep a deterministic order
    fire('schema.install', []);
    db_upsert('fb_settings', ['key' => 'schema_version', 'value' => (string)SCHEMA_VERSION], ['key']);
    request_cache('settings', null, true);
}

/** Default groups, categories and the welcome topic. Runs once at install. */
function schema_seed(string $admin_name, string $admin_email, string $admin_password): int
{
    $groups = [
        ['Administrators', 'admin', '#e7672e', 1, 1, 0],
        ['Moderators', 'moderator', '#4d698e', 0, 1, 1],
        ['Members', 'member', '', 0, 0, 2],
    ];
    foreach ($groups as $g) {
        db_insert_ignore('fb_groups', ['name' => $g[0], 'slug' => $g[1], 'color' => $g[2], 'is_admin' => $g[3], 'is_mod' => $g[4], 'permissions' => json_encode_value(['post', 'reply', 'upload', 'edit_own', 'delete_own']), 'sort' => $g[5]]);
    }
    $admin_group = (int)val("SELECT id FROM fb_groups WHERE slug='admin'");
    $categories = [
        ['General', 'general', 'Talk about anything and everything.', 0],
        ['Announcements', 'announcements', 'Official news and updates.', 1],
        ['Help & Support', 'help', 'Ask questions and get help.', 2],
    ];
    foreach ($categories as $c) {
        db_insert_ignore('fb_categories', ['name' => $c[0], 'slug' => $c[1], 'description' => $c[2], 'color' => '', 'sort' => $c[3]]);
    }
    $uid = user_create($admin_name, $admin_email, $admin_password, $admin_group);
    $cat = (int)val("SELECT id FROM fb_categories WHERE slug='general'");
    $body = "Welcome to your new **FlatBB** forum!\n\nThis is your first topic. A few things you can do next:\n\n- Sign in with the administrator account you just created\n- Open **Admin** from the user menu to configure the site, categories and plugins\n- Read `CLAUDE.md` and `docs/PLUGIN.md` if you want to build plugins with an AI assistant\n\nHappy posting!";
    topic_create($cat, $uid, 'Welcome to FlatBB', $body, ['welcome'], true);
    save_settings(['cron_key' => random_token(12), 'installed_at' => (string)now()]);
    return $uid;
}
