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

 class ActivityLogger
 {


     public function __construct()
     {



         // Hooks to WordPress actions and filters during initialization
         add_action('init', array($this, 'registerActivityPostType'));
         add_action('transition_post_status', array($this, 'trackPostChanges'), 10, 3);
         add_action('admin_menu', array($this, 'removePublishBox'));
         add_action('before_delete_post', array($this, 'logDelete'));
         add_action('untrash_post', array($this, 'trackPostRestored'));
         add_action('load-edit.php', array($this, 'trackBulkActions'));
         add_filter('bulk_actions-edit-activity', array($this, 'removeBulkActions'));
         add_filter('post_row_actions', array($this, 'removeQuickEdit'), 10, 2);
         add_action('admin_head', array($this, 'removePublishFilter'));
         add_filter('post_row_actions', array($this, 'updateRowActions'), 10, 2);
         add_action('add_meta_boxes', array($this, 'addActivityDetailBox'));
         add_action('user_register', array($this, 'trackUserRegistered'));
         add_action('delete_user', array($this, 'trackUserDeleted'));
         add_action('profile_update', array($this, 'trackUserUpdated'), 10, 2);
         add_action('edit_attachment', array($this, 'trackMediaChanges'));
         add_action('add_attachment', array($this, 'trackMediaAdded'));
         add_action('delete_attachment', array($this, 'trackMediaDeleted'));
         add_filter('manage_activity_posts_columns', array($this, 'addActivityColumns'));
         add_action('manage_activity_posts_custom_column', array($this, 'manageActivityColumns'), 10, 2);
         add_action('activated_plugin', array($this, 'trackPluginActivation'));
         add_action('deactivated_plugin', array($this, 'trackPluginDeactivation'));
         add_action('exportPostTypeToLog', array($this, 'exportPostTypeToLog'));


     }


     // Track plugin activation
     public function trackPluginActivation($plugin)
     {
         $currentUser = wp_get_current_user();
         $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
         $pluginName = $pluginData['Name'];
         $this->logActivity("Plugin '{$pluginName}' was activated", $currentUser->ID);
     }

     // Track plugin deactivation
     public function trackPluginDeactivation($plugin)
     {
         $currentUser = wp_get_current_user();
         $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
         $pluginName = $pluginData['Name'];
         $this->logActivity("Plugin '{$pluginName}' was deactivated", $currentUser->ID);
     }

     // Add new columns to the activity post type
     public function addActivityColumns($columns)
     {
         $newColumns = array(
             'cb' => $columns['cb'],
             'title' => $columns['title'],
             'id' => __('ID', 'wp-activity'),
             'user' => __('User', 'wp-activity'),
             'date' => $columns['date'],
         );

         return $newColumns;
     }

     // Manage the content of custom columns
     public function manageActivityColumns($column, $postID)
     {
         switch ($column) {
             case 'id':
                 $trackedItemID = get_post_meta($postID, '_modified_post_id', true);
                 echo $trackedItemID ? "<a href='" . get_edit_post_link($trackedItemID) . "'>$trackedItemID</a>" : 'N/A';
                 break;
             case 'user':
                 $userID = get_post_meta($postID, '_activity_user_id', true);
                 $userInfo = get_userdata($userID);
                 echo $userInfo ? $userInfo->user_login : 'N/A';
                 break;
                 // Handle other custom columns if needed
         }
     }

     public function trackMediaAdded($postID)
     {
         $post = get_post($postID);
         $currentUser = wp_get_current_user();
         $activityArgs = array(
             'post_type' => 'activity',
             'post_title' => $post->post_title . ' was added',
             'post_status' => 'publish',
         );
         $this->logActivity($activityArgs['post_title'], $currentUser->ID, $post->ID);
     }

     public function trackMediaDeleted($postID)
     {
         $post = get_post($postID);
         $currentUser = wp_get_current_user();
         $activityArgs = array(
             'post_type' => 'activity',
             'post_title' => $post->post_title . ' was deleted',
             'post_status' => 'publish',
         );
         $this->logActivity($activityArgs['post_title'], $currentUser->ID, $post->ID);
     }

     public function trackMediaChanges($postID)
     {
         $post = get_post($postID);

         if ($post->post_type == 'attachment') {
             $currentUser = wp_get_current_user();
             $activityArgs = array(
                 'post_type' => 'activity',
                 'post_title' => $post->post_title . ' was modified',
                 'post_status' => 'publish',
             );
             $this->logActivity($activityArgs['post_title'], $currentUser->ID, $post->ID);
         }
     }

     public function trackUserRegistered($userID)
     {
         $userInfo = get_userdata($userID);
         $this->logActivity("User {$userInfo->user_login} was registered", $userID);
     }

     public function trackUserDeleted($userID)
     {
         $this->logActivity("User with ID {$userID} was deleted", $userID);
     }

     public function trackUserUpdated($userID, $oldUserData)
     {
         $userInfo = get_userdata($userID);
         $this->logActivity("User {$userInfo->user_login} was updated", $userID, $userID);
     }

     private function logActivity($message, $userID, $modifiedPostID = 0)
     {
         $activityArgs = array(
             'post_type' => 'activity',
             'post_title' => $message,
             'post_status' => 'publish',
         );
         $activityID = wp_insert_post($activityArgs);
         add_post_meta($activityID, '_activity_user_id', $userID, true);
         if ($modifiedPostID > 0) {
             add_post_meta($activityID, '_modified_post_id', $modifiedPostID, true);
         }
     }

     public function displayActivityDetails($post)
     {
         $userID = get_post_meta($post->ID, '_activity_user_id', true);
         $userInfo = get_userdata($userID);
         $modifiedPostID = get_post_meta($post->ID, '_modified_post_id', true);
         echo "<div><strong>Activity:</strong> {$post->post_title}</div>";
         echo "<div><strong>User:</strong> {$userInfo->user_login}</div>";
         echo "<div><strong>Date:</strong> {$post->post_date}</div>";
         echo "<div><strong>ID:</strong> {$modifiedPostID}</div>";

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

     public function updateRowActions($actions, $post)
     {
         if ($post->post_type == 'activity') {
             unset($actions['inline hide-if-no-js']);
             if (isset($actions['edit'])) {
                 $actions['edit'] = str_replace('Edit', 'View', $actions['edit']);
             }
         }
         return $actions;
     }

     public function removeQuickEdit($actions, $post)
     {
         if ($post->post_type == 'activity') {
             unset($actions['inline hide-if-no-js']);
         }
         return $actions;
     }

     public function removePublishFilter()
     {
         global $typenow;
         if ('activity' === $typenow) {
             add_filter('views_edit-activity', function ($views) {
                 unset($views['publish']);
                 return $views;
             });
         }
     }

     public function trackBulkActions()
     {
         if (!isset($_REQUEST['action']) || $_REQUEST['action'] != 'trash' || $_REQUEST['post_type'] == 'activity') {
             return;
         }

         $postIDs = array_map('intval', (array) $_REQUEST['post']);
         foreach ($postIDs as $postID) {
             $this->logDelete($postID);
         }
     }

     public function logDelete($postID)
     {
         $post = get_post($postID);
         if ($post->post_type == 'activity' || $post->post_status == 'trash') {
             return;
         }

         $currentUser = wp_get_current_user();
         $activityArgs = array(
             'post_type' => 'activity',
             'post_title' => $post->post_title . ' was deleted',
             'post_status' => 'publish',
         );
         $activityID = wp_insert_post($activityArgs);
         add_post_meta($activityID, '_activity_user_id', $currentUser->ID, true);
         add_post_meta($activityID, '_modified_post_id', $postID, true);
     }

     public function displayActivityUser($post)
     {
         $userID = get_post_meta($post->ID, '_activity_user_id', true);
         $userInfo = get_userdata($userID);
         echo "<div>User: {$userInfo->user_login}</div>";
     }

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

     public function removeBulkActions($actions)
     {
         unset($actions['edit']);
         return $actions;
     }

     public function trackPostRestored($postID)
     {
         $post = get_post($postID);
         if ($post->post_type == 'activity') {
             return;
         }

         $currentUser = wp_get_current_user();
         $activityArgs = array(
             'post_type' => 'activity',
             'post_title' => $post->post_title . ' was restored',
             'post_status' => 'publish',
         );

         $activityID = wp_insert_post($activityArgs);
         add_post_meta($activityID, '_activity_user_id', $currentUser->ID, true);
         add_post_meta($activityID, '_modified_post_id', $postID, true);
     }

     public function trackPostChanges($newStatus, $oldStatus, $post)
     {
         if ($post->post_type == 'activity' || $newStatus == 'auto-draft' || $newStatus == 'inherit') {
             return;
         }

         $currentUser = wp_get_current_user();
         $activityArgs = array(
             'post_type' => 'activity',
             'post_status' => 'publish',
         );

         if ($newStatus == 'trash') {
             if (get_post_meta($post->ID, '_trashed_logged', true)) {
                 return;
             }

             $activityArgs['post_title'] = $post->post_title . ' was trashed';
             add_post_meta($post->ID, '_trashed_logged', '1', true);
         } else if ($newStatus == 'publish' && $oldStatus == 'trash') {
             $activityArgs['post_title'] = $post->post_title . ' was restored';
         } else if ($newStatus == 'publish' && $oldStatus != 'publish') {
             $activityArgs['post_title'] = $post->post_title . ' was published';
         } else if ($newStatus == 'publish' && $oldStatus == 'publish') {
             $activityArgs['post_title'] = $post->post_title . ' was updated';
         } else {
             $activityArgs['post_title'] = "{$post->post_title} status changed from {$oldStatus} to {$newStatus}";
         }

         $this->logActivity($activityArgs['post_title'], $currentUser->ID, $post->ID);
     }

     public function removePublishBox()
     {
         remove_meta_box('submitdiv', 'activity', 'side');
     }

     public function exportPostTypeToLog()
     {
         // Consulta para recuperar todas las entradas del tipo de publicación personalizada
         $args = array(
             'post_type' => 'activity',
             'posts_per_page' => -1, // Obtener todas las entradas
             'post_status' => 'publish', // O el estado deseado
         );

         $query = new WP_Query($args);

         // Nombre del archivo de registro
         $log_filename = 'post_type_export.log';

         // Abre el archivo de registro para escritura (o crea uno nuevo si no existe)
         $log_file = fopen($log_filename, 'w');

         if ($query->have_posts()) {
             while ($query->have_posts()) {
                 $query->the_post();
                 $post_title = get_the_title();
                 $post_content = get_the_content();
                 $log_entry = "Title: $post_title\nContent: $post_content\n\n";

                 // Escribe la entrada en el archivo de registro
                 fwrite($log_file, $log_entry);
             }
         }

         // Cierra el archivo de registro
         fclose($log_file);

         // Restaura la consulta original de WordPress
         wp_reset_postdata();
     }

 }

 new ActivityLogger();
