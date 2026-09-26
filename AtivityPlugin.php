<?php

/*
    Plugin Name: Activity Tool
    Description: A WordPress activity log plugin tracks important events and actions on the website.
    Version: 1.0
    Author: RolandoToto
    Author URI: http://rolandototo.dev
    License: GPL3
    License URI: http://www.gnu.org/licenses/gpl-2.0.html
    Text Domain: activity-tool
    Requires at least: 5.0
    Requires PHP: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ActivityLogger
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
            $message = "{$title} was trashed";
        } elseif ('trash' === $oldStatus) {
            $message = "{$title} was restored";
        } elseif ('publish' === $newStatus) {
            $message = 'publish' === $oldStatus ? "{$title} was updated" : "{$title} was published";
        } elseif (in_array($oldStatus, array('new', 'auto-draft'), true)) {
            $message = "{$title} was created as {$newStatus}";
        } else {
            $message = "{$title} status changed from {$oldStatus} to {$newStatus}";
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

        $this->logActivity($this->postLabel($post) . ' was permanently deleted', get_current_user_id(), $post->ID);
    }

    public function trackMediaAdded($postID)
    {
        $this->logActivity($this->postLabel(get_post($postID)) . ' was added', get_current_user_id(), $postID);
    }

    public function trackMediaChanges($postID)
    {
        $this->logActivity($this->postLabel(get_post($postID)) . ' was modified', get_current_user_id(), $postID);
    }

    public function trackMediaDeleted($postID)
    {
        $this->logActivity($this->postLabel(get_post($postID)) . ' was deleted', get_current_user_id(), $postID);
    }

    public function trackUserRegistered($userID)
    {
        $user = get_userdata($userID);
        // On self-registration nobody is logged in, so the new user is the actor.
        $actor = get_current_user_id() ? get_current_user_id() : $userID;
        $this->logActivity("User {$user->user_login} was registered", $actor, 0, $userID);
    }

    public function trackUserUpdated($userID, $oldUserData)
    {
        $user = get_userdata($userID);
        $actor = get_current_user_id() ? get_current_user_id() : $userID;
        $this->logActivity("User {$user->user_login} was updated", $actor, 0, $userID);
    }

    // Fires before the user is deleted, so the login is still available.
    public function trackUserDeleted($userID)
    {
        $user = get_userdata($userID);
        $login = $user ? $user->user_login : "#{$userID}";
        $this->logActivity("User {$login} was deleted", get_current_user_id(), 0, $userID);
    }

    public function trackPluginActivation($plugin)
    {
        $this->logActivity("Plugin '{$this->pluginName($plugin)}' was activated", get_current_user_id());
    }

    public function trackPluginDeactivation($plugin)
    {
        $this->logActivity("Plugin '{$this->pluginName($plugin)}' was deactivated", get_current_user_id());
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

    // Only content types with an admin UI are logged; internal types such as
    // revisions, menu items and changesets are skipped.
    private function isTrackedPost($post)
    {
        if (!$post || 'activity' === $post->post_type) {
            return false;
        }
        $type = get_post_type_object($post->post_type);
        return $type && $type->show_ui;
    }

    private function postLabel($post)
    {
        if (!$post) {
            return 'An item';
        }
        return '' !== $post->post_title ? $post->post_title : "(no title) #{$post->ID}";
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
        $args = array(
            'public' => false,
            'label'  => 'Activity',
            'labels' => array(
                'edit_item' => 'Activity Details',
            ),
            'show_ui' => true,
            'capability_type' => 'post',
            'hierarchical' => false,
            'rewrite' => array('slug' => 'activity'),
            'query_var' => true,
            'supports' => false,
            'capabilities' => array(
                'create_posts' => 'do_not_allow',
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
            'id'    => __('ID', 'wp-activity'),
            'user'  => __('User', 'wp-activity'),
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
                    echo 'N/A';
                } elseif ($link) {
                    echo '<a href="' . esc_url($link) . '">' . esc_html($itemID) . '</a>';
                } else {
                    echo esc_html($itemID);
                }
                break;
            case 'user':
                $userInfo = get_userdata(get_post_meta($postID, '_activity_user_id', true));
                echo $userInfo ? esc_html($userInfo->user_login) : 'N/A';
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
        echo '<div><strong>Activity:</strong> ' . esc_html($post->post_title) . '</div>';
        echo '<div><strong>User:</strong> ' . esc_html($userInfo ? $userInfo->user_login : 'N/A') . '</div>';
        echo '<div><strong>Date:</strong> ' . esc_html($post->post_date) . '</div>';
        echo '<div><strong>ID:</strong> ' . esc_html($itemID ? $itemID : 'N/A') . '</div>';
    }

    public function addActivityDetailBox()
    {
        add_meta_box(
            'activity_details',
            'Activity Details',
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
                $actions['edit'] = '<a href="' . esc_url(get_edit_post_link($post->ID)) . '">View</a>';
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

new ActivityLogger();
