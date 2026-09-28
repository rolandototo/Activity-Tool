<?php
/**
 * Plugin Name:       Activity Tool
 * Plugin URI:        https://github.com/rolandototo/Activity-Tool
 * Description:       Activity log for WordPress: records post, media, user and plugin events in a read-only admin list.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rolando Escobar
 * Author URI:        https://rolandowp.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       activity-tool
 *
 * The main file keeps its original name so existing installs stay active.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ActivityToolLogger
{
    public function __construct()
    {
        // Log screen
        add_action('init', array($this, 'registerActivityPostType'));
        add_action('admin_menu', array($this, 'removePublishBox'));
        add_filter('bulk_actions-edit-activity', array($this, 'removeBulkActions'));
        add_filter('post_row_actions', array($this, 'updateRowActions'), 10, 2);
        add_filter('views_edit-activity', array($this, 'removePublishView'));
        add_action('add_meta_boxes', array($this, 'addActivityDetailBox'));
        add_filter('manage_activity_posts_columns', array($this, 'addActivityColumns'));
        add_action('manage_activity_posts_custom_column', array($this, 'manageActivityColumns'), 10, 2);

        // Content. Bulk actions and restores go through transition_post_status too,
        // so no separate hooks are needed for them.
        add_action('transition_post_status', array($this, 'trackPostChanges'), 10, 3);
        add_action('before_delete_post', array($this, 'trackPostDeleted'));

        // Media
        add_action('add_attachment', array($this, 'trackMediaAdded'));
        add_action('edit_attachment', array($this, 'trackMediaChanges'));
        add_action('delete_attachment', array($this, 'trackMediaDeleted'));

        // Users
        add_action('user_register', array($this, 'trackUserRegistered'));
        add_action('profile_update', array($this, 'trackUserUpdated'), 10, 2);
        add_action('delete_user', array($this, 'trackUserDeleted'));
        // On multisite, deleting a user from the network fires wpmu_delete_user
        // instead of delete_user.
        add_action('wpmu_delete_user', array($this, 'trackUserDeleted'));

        // Plugins
        add_action('activated_plugin', array($this, 'trackPluginActivation'));
        add_action('deactivated_plugin', array($this, 'trackPluginDeactivation'));
    }

    /* ------------------------------------------------------------------
     * Events
     * ------------------------------------------------------------------ */

    public function trackPostChanges($newStatus, $oldStatus, $post)
    {
        if (!$this->isTrackedPost($post) || in_array($newStatus, array('auto-draft', 'inherit'), true)) {
            return;
        }

        // Saving without a status change only matters for published content
        // (a draft saved twice used to log "changed from draft to draft").
        if ($newStatus === $oldStatus && 'publish' !== $newStatus) {
            return;
        }

        $title = $this->postLabel($post);

        if ('trash' === $newStatus) {
            /* translators: %s: post title */
            $message = sprintf(__('%s was trashed', 'activity-tool'), $title);
        } elseif ('trash' === $oldStatus) {
            /* translators: %s: post title */
            $message = sprintf(__('%s was restored', 'activity-tool'), $title);
        } elseif ('publish' === $newStatus && 'publish' === $oldStatus) {
            /* translators: %s: post title */
            $message = sprintf(__('%s was updated', 'activity-tool'), $title);
        } elseif ('publish' === $newStatus) {
            /* translators: %s: post title */
            $message = sprintf(__('%s was published', 'activity-tool'), $title);
        } elseif (in_array($oldStatus, array('new', 'auto-draft'), true)) {
            /* translators: 1: post title, 2: post status */
            $message = sprintf(__('%1$s was created as %2$s', 'activity-tool'), $title, $newStatus);
        } else {
            /* translators: 1: post title, 2: old status, 3: new status */
            $message = sprintf(__('%1$s status changed from %2$s to %3$s', 'activity-tool'), $title, $oldStatus, $newStatus);
        }

        $this->logActivity($message, get_current_user_id(), $post->ID);
    }

    // Fires for every permanent delete, whether or not the post was in the trash.
    public function trackPostDeleted($postID)
    {
        $post = get_post($postID);
        if (!$this->isTrackedPost($post) || 'auto-draft' === $post->post_status) {
            return;
        }

        /* translators: %s: post title */
        $this->logActivity(sprintf(__('%s was permanently deleted', 'activity-tool'), $this->postLabel($post)), get_current_user_id(), $post->ID);
    }

    public function trackMediaAdded($postID)
    {
        /* translators: %s: media title */
        $this->logActivity(sprintf(__('%s was added', 'activity-tool'), $this->postLabel(get_post($postID))), get_current_user_id(), $postID);
    }

    public function trackMediaChanges($postID)
    {
        /* translators: %s: media title */
        $this->logActivity(sprintf(__('%s was modified', 'activity-tool'), $this->postLabel(get_post($postID))), get_current_user_id(), $postID);
    }

    public function trackMediaDeleted($postID)
    {
        /* translators: %s: media title */
        $this->logActivity(sprintf(__('%s was deleted', 'activity-tool'), $this->postLabel(get_post($postID))), get_current_user_id(), $postID);
    }

    public function trackUserRegistered($userID)
    {
        $user = get_userdata($userID);
        // On self-registration nobody is logged in, so the new user is the actor.
        $actor = get_current_user_id() ? get_current_user_id() : $userID;
        /* translators: %s: user login */
        $this->logActivity(sprintf(__('User %s was registered', 'activity-tool'), $user->user_login), $actor, 0, $userID);
    }

    public function trackUserUpdated($userID, $oldUserData)
    {
        $user = get_userdata($userID);
        $actor = get_current_user_id() ? get_current_user_id() : $userID;
        /* translators: %s: user login */
        $this->logActivity(sprintf(__('User %s was updated', 'activity-tool'), $user->user_login), $actor, 0, $userID);
    }

    // Fires before the user is deleted, so the login is still available.
    public function trackUserDeleted($userID)
    {
        $user = get_userdata($userID);
        $login = $user ? $user->user_login : '#' . $userID;
        /* translators: %s: user login */
        $this->logActivity(sprintf(__('User %s was deleted', 'activity-tool'), $login), get_current_user_id(), 0, $userID);
    }

    public function trackPluginActivation($plugin)
    {
        /* translators: %s: plugin name */
        $this->logActivity(sprintf(__('Plugin "%s" was activated', 'activity-tool'), $this->pluginName($plugin)), get_current_user_id());
    }

    public function trackPluginDeactivation($plugin)
    {
        /* translators: %s: plugin name */
        $this->logActivity(sprintf(__('Plugin "%s" was deactivated', 'activity-tool'), $this->pluginName($plugin)), get_current_user_id());
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Stores one log entry.
     *
     * @param string $message      Event message, stored as the post title.
     * @param int    $userID       User who did it (0 for cron or WP-CLI without --user).
     * @param int    $postID       Affected post or attachment, if any.
     * @param int    $objectUserID Affected user, for user events.
     */
    private function logActivity($message, $userID, $postID = 0, $objectUserID = 0)
    {
        $meta = array('_activity_user_id' => (int) $userID);
        if ($postID > 0) {
            $meta['_modified_post_id'] = (int) $postID;
        }
        if ($objectUserID > 0) {
            $meta['_modified_user_id'] = (int) $objectUserID;
        }

        wp_insert_post(array(
            'post_type'   => 'activity',
            'post_title'  => $message,
            'post_status' => 'publish',
            'meta_input'  => $meta,
        ));
    }

    // By default only content types with an admin UI are logged; internal types
    // such as revisions, menu items and changesets are skipped.
    private function isTrackedPost($post)
    {
        if (!$post || 'activity' === $post->post_type) {
            return false;
        }
        $type = get_post_type_object($post->post_type);

        /**
         * Filters whether status changes and deletes of a post are logged.
         *
         * @param bool    $tracked Default true for post types with an admin UI.
         * @param WP_Post $post    The post.
         */
        return (bool) apply_filters('activity_tool_track_post', $type && $type->show_ui, $post);
    }

    private function postLabel($post)
    {
        if (!$post) {
            return __('An item', 'activity-tool');
        }
        if ('' !== $post->post_title) {
            return $post->post_title;
        }
        /* translators: %d: post ID */
        return sprintf(__('(no title) #%d', 'activity-tool'), $post->ID);
    }

    private function pluginName($plugin)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
        return !empty($data['Name']) ? $data['Name'] : $plugin;
    }

    /* ------------------------------------------------------------------
     * Log screen
     * ------------------------------------------------------------------ */

    public function registerActivityPostType()
    {
        /**
         * Filters the capability needed to see and delete log entries.
         *
         * @param string $capability Default "manage_options" (administrators).
         */
        $cap = apply_filters('activity_tool_capability', 'manage_options');
        if (!is_string($cap) || '' === $cap) {
            $cap = 'manage_options';
        }

        $args = array(
            'public' => false,
            'label'  => __('Activity', 'activity-tool'),
            'labels' => array(
                'edit_item' => __('Activity Details', 'activity-tool'),
            ),
            'show_ui' => true,
            'capability_type' => 'post',
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => false,
            // Only users with $cap (administrators by default) can see or
            // delete entries, and nobody can create or publish them by hand.
            // With capability_type "post", editors could otherwise read and
            // delete the log.
            'capabilities' => array(
                'create_posts'           => 'do_not_allow',
                'publish_posts'          => 'do_not_allow',
                'edit_posts'             => $cap,
                'edit_others_posts'      => $cap,
                'edit_published_posts'   => $cap,
                'edit_private_posts'     => $cap,
                'read_private_posts'     => $cap,
                'delete_posts'           => $cap,
                'delete_others_posts'    => $cap,
                'delete_published_posts' => $cap,
                'delete_private_posts'   => $cap,
            ),
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-clock',
        );
        register_post_type('activity', $args);
    }

    // Add new columns to the activity post type
    public function addActivityColumns($columns)
    {
        return array(
            'cb'    => $columns['cb'],
            'title' => $columns['title'],
            'id'    => __('ID', 'activity-tool'),
            'user'  => __('User', 'activity-tool'),
            'date'  => $columns['date'],
        );
    }

    // Manage the content of custom columns
    public function manageActivityColumns($column, $postID)
    {
        switch ($column) {
            case 'id':
                list($itemID, $link) = $this->affectedItem($postID);
                if (!$itemID) {
                    esc_html_e('N/A', 'activity-tool');
                } elseif ($link) {
                    echo '<a href="' . esc_url($link) . '">' . esc_html($itemID) . '</a>';
                } else {
                    echo esc_html($itemID);
                }
                break;
            case 'user':
                $userInfo = get_userdata(get_post_meta($postID, '_activity_user_id', true));
                echo $userInfo ? esc_html($userInfo->user_login) : esc_html__('N/A', 'activity-tool');
                break;
        }
    }

    /**
     * ID of the affected user or post, and a link to edit it if it still exists.
     *
     * @return array { 0: int ID or 0, 1: string edit URL or '' }
     */
    private function affectedItem($activityID)
    {
        $userID = (int) get_post_meta($activityID, '_modified_user_id', true);
        if ($userID) {
            return array($userID, get_userdata($userID) ? get_edit_user_link($userID) : '');
        }

        $postID = (int) get_post_meta($activityID, '_modified_post_id', true);
        if ($postID) {
            return array($postID, get_post($postID) ? (string) get_edit_post_link($postID) : '');
        }

        return array(0, '');
    }

    public function displayActivityDetails($post)
    {
        $userInfo = get_userdata(get_post_meta($post->ID, '_activity_user_id', true));
        list($itemID) = $this->affectedItem($post->ID);
        $na = __('N/A', 'activity-tool');

        echo '<div><strong>' . esc_html__('Activity:', 'activity-tool') . '</strong> ' . esc_html($post->post_title) . '</div>';
        echo '<div><strong>' . esc_html__('User:', 'activity-tool') . '</strong> ' . esc_html($userInfo ? $userInfo->user_login : $na) . '</div>';
        echo '<div><strong>' . esc_html__('Date:', 'activity-tool') . '</strong> ' . esc_html($post->post_date) . '</div>';
        echo '<div><strong>' . esc_html__('ID:', 'activity-tool') . '</strong> ' . esc_html($itemID ? $itemID : $na) . '</div>';
    }

    public function addActivityDetailBox()
    {
        add_meta_box(
            'activity_details',
            __('Activity Details', 'activity-tool'),
            array($this, 'displayActivityDetails'),
            'activity',
            'normal',
            'high'
        );
    }

    // Entries are read-only: no Quick Edit, and "Edit" becomes "View".
    public function updateRowActions($actions, $post)
    {
        if ('activity' === $post->post_type) {
            unset($actions['inline hide-if-no-js']);
            if (isset($actions['edit'])) {
                $actions['edit'] = '<a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html__('View', 'activity-tool') . '</a>';
            }
        }
        return $actions;
    }

    public function removePublishView($views)
    {
        unset($views['publish']);
        return $views;
    }

    public function removeBulkActions($actions)
    {
        unset($actions['edit']);
        return $actions;
    }

    public function removePublishBox()
    {
        remove_meta_box('submitdiv', 'activity', 'side');
    }
}

new ActivityToolLogger();
