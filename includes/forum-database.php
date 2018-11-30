<?php

if (!defined('ABSPATH')) exit;

class AsgarosForumDatabase {
    private $db;
    private $db_version = 36;
    private $tables;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->setTables();
        register_activation_hook(__FILE__, array($this, 'activatePlugin'));
        add_action('wpmu_new_blog', array($this, 'buildSubsite'), 10, 6);
        add_filter('wpmu_drop_tables', array($this, 'deleteSubsite'));
        add_action('wp_loaded', array($this, 'buildDatabase'));
	}

    private function setTables() {
        $this->tables = new stdClass();
        $this->tables->forums       = $this->db->prefix.'forum_forums';
        $this->tables->topics       = $this->db->prefix.'forum_topics';
        $this->tables->posts        = $this->db->prefix.'forum_posts';
        $this->tables->reports      = $this->db->prefix.'forum_reports';
        $this->tables->reactions    = $this->db->prefix.'forum_reactions';
        $this->tables->ads          = $this->db->prefix.'forum_ads';
    }

    public function getTables() {
        return $this->tables;
    }

    public function activatePlugin($networkwide) {
        if (function_exists('is_multisite') && is_multisite()) {
            // Check if it is a network activation. If so, run the database-creation for each id.
            if ($networkwide) {
                $old_blog =  $this->db->blogid;

                // Get all blog ids
                $blogids = $this->db->get_col('SELECT blog_id FROM '.$this->db->blogs);

                foreach ($blogids as $blog_id) {
                    switch_to_blog($blog_id);
                    $this->setTables();
                    $this->buildDatabase();
                }

                switch_to_blog($old_blog);
                $this->setTables();
            }
        } else {
            $this->buildDatabase();
        }
    }

    // Create tables for a new subsite in a multisite installation.
    public function buildSubsite($blog_id, $user_id, $domain, $path, $site_id, $meta) {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once(ABSPATH.'/wp-admin/includes/plugin.php');
        }

        if (is_plugin_active_for_network('asgaros-forum/asgaros-forum.php')) {
            switch_to_blog($blog_id);
            $this->setTables();
            $this->buildDatabase();
            restore_current_blog();
            $this->setTables();
        }
    }

    // Delete tables during a subsite uninstall.
    public function deleteSubsite($tables) {
        $tables[] = $this->db->prefix.'forum_forums';
        $tables[] = $this->db->prefix.'forum_topics';
        $tables[] = $this->db->prefix.'forum_posts';
        $tables[] = $this->db->prefix.'forum_reports';
        $tables[] = $this->db->prefix.'forum_reactions';
        $tables[] = $this->db->prefix.'forum_ads';

        // Delete data which has been used in old versions of the plugin.
        $tables[] = $this->db->prefix.'forum_threads';
        return $tables;
    }

    public function check_index($table_name, $index_name) {
        return $this->db->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}';");
    }

    public function buildDatabase() {
        // Start the installation/update logic.
        global $asgarosforum;

        $database_version_installed = get_option('asgarosforum_db_version');

        if ($database_version_installed != $this->db_version) {
            // Rename old table.
            $renameTable = $this->db->get_results('SHOW TABLES LIKE "'.$this->db->prefix.'forum_threads";');
            if (!empty($renameTable)) {
                $this->db->query('RENAME TABLE '.$this->db->prefix.'forum_threads TO '.$this->tables->topics.';');
            }

            $charset_collate = $this->db->get_charset_collate();

            $sql = array();

            $sql[] = "CREATE TABLE ".$this->tables->forums." (
            id int(11) NOT NULL auto_increment,
            name varchar(255) NOT NULL default '',
            parent_id int(11) NOT NULL default '0',
            parent_forum int(11) NOT NULL default '0',
            description varchar(255) NOT NULL default '',
            icon varchar(255) NOT NULL default '',
            sort int(11) NOT NULL default '0',
            closed int(11) NOT NULL default '0',
            slug varchar(255) NOT NULL default '',
            PRIMARY KEY  (id)
            ) $charset_collate;";

            $sql[] = "CREATE TABLE ".$this->tables->topics." (
            id int(11) NOT NULL auto_increment,
            parent_id int(11) NOT NULL default '0',
            views int(11) NOT NULL default '0',
            name varchar(255) NOT NULL default '',
            sticky int(1) NOT NULL default '0',
            closed int(1) NOT NULL default '0',
            approved int(1) NOT NULL default '1',
            slug varchar(255) NOT NULL default '',
            PRIMARY KEY  (id)
            ) $charset_collate;";

            $sql[] = "CREATE TABLE ".$this->tables->posts." (
            id int(11) NOT NULL auto_increment,
            text longtext,
            parent_id int(11) NOT NULL default '0',
            forum_id int(11) NOT NULL default '0',
            date datetime NOT NULL default '1000-01-01 00:00:00',
            date_edit datetime NOT NULL default '1000-01-01 00:00:00',
            author_id int(11) NOT NULL default '0',
            author_edit int(11) NOT NULL default '0',
            uploads longtext,
            PRIMARY KEY  (id)
            ) $charset_collate;";

            $sql[] = "CREATE TABLE ".$this->tables->reports." (
            post_id int(11) NOT NULL default '0',
            reporter_id int(11) NOT NULL default '0',
            PRIMARY KEY  (post_id, reporter_id)
            ) $charset_collate;";

            $sql[] = "CREATE TABLE ".$this->tables->reactions." (
            post_id int(11) NOT NULL default '0',
            user_id int(11) NOT NULL default '0',
            reaction varchar(20) NOT NULL default '',
            PRIMARY KEY  (post_id, user_id)
            ) $charset_collate;";

            $sql[] = "CREATE TABLE ".$this->tables->ads." (
            id int(11) NOT NULL auto_increment,
            name varchar(255) NOT NULL default '',
            code longtext,
            active int(1) NOT NULL default '0',
            locations longtext,
            PRIMARY KEY  (id)
            ) $charset_collate;";

            require_once(ABSPATH.'wp-admin/includes/upgrade.php');

            dbDelta($sql);

            // First time installation instructions.
            if ($database_version_installed == false) {
                // Try to create a new page for the forum.
                $page_id = wp_insert_post(
                    array(
                        'post_content'      => '[forum]',
                        'post_title'        => 'Community',
                        'post_status'       => 'publish',
                        'post_type'         => 'page',
                        'comment_status'    => 'closed',
                        'ping_status'       => 'closed'
                    )
                );

                // If the page could get created, save it in the forum-options.
                if ($page_id && !is_wp_error($page_id)) {
                    $asgarosforum->loadOptions();
                    $asgarosforum->options['location'] = $page_id;
                    $asgarosforum->saveOptions($asgarosforum->options);
                }

                update_option('asgarosforum_db_version', 1);
            }

            if ($database_version_installed < 5) {
                // Because most of the WordPress users are using a MySQL version below 5.6,
                // we have to set the ENGINE for the post-table to MyISAM because InnoDB doesnt
                // support FULLTEXT before MySQL version 5.6.
                $this->db->query('ALTER TABLE '.$this->tables->posts.' ENGINE = MyISAM;');
                $this->db->query('ALTER TABLE '.$this->tables->posts.' ADD FULLTEXT (text);');

                update_option('asgarosforum_db_version', 5);
            }

            // Create forum slugs.
            if ($database_version_installed < 6) {
                $forums = $this->db->get_results("SELECT id, name FROM ".$this->tables->forums." WHERE slug = '' ORDER BY id ASC;");

                foreach ($forums as $forum) {
                    $slug = $asgarosforum->rewrite->create_unique_slug($forum->name, $this->tables->forums, 'forum');
                    $this->db->update($this->tables->forums, array('slug' => $slug), array('id' => $forum->id), array('%s'), array('%d'));
                }

                update_option('asgarosforum_db_version', 6);
            }

            // Add index to posts.author_id to make countings faster.
            if ($database_version_installed < 11) {
                if (!$this->check_index($this->tables->posts, 'author_id')) {
                    $this->db->query('ALTER TABLE '.$this->tables->posts.' ADD INDEX(author_id);');
                }

                update_option('asgarosforum_db_version', 11);
            }

            // Add index to posts.parent_id for faster queries.
            if ($database_version_installed < 12) {
                if (!$this->check_index($this->tables->posts, 'parent_id')) {
                    $this->db->query('ALTER TABLE '.$this->tables->posts.' ADD INDEX(parent_id);');
                }

                update_option('asgarosforum_db_version', 12);
            }

            // Add existing usergroups to a default usergroups category and/or create an example usergroup.
            if ($database_version_installed < 13) {
                // Initialize taxonomy first.
                AsgarosForumUserGroups::initializeTaxonomy();

                // Create a new example category first.
                $defaultCategoryName = __('Custom Usergroups', 'asgaros-forum');
                $defaultCategory = AsgarosForumUserGroups::insertUserGroupCategory($defaultCategoryName);

                // Ensure that no error happened.
                if (!is_wp_error($defaultCategory)) {
                    // Now get all existing elements.
                    $existingCategories = AsgarosForumUserGroups::getUserGroupCategories();

                    // When there is only one element, then it is the newly created category.
                    if (count($existingCategories) > 1) {
                        // Move every existing usergroup into the new default category.
                        foreach ($existingCategories as $category) {
                            // But ensure to not move the new default category into it.
                            if ($category->term_id != $defaultCategory['term_id']) {
                                $color = AsgarosForumUserGroups::getUserGroupColor($category->term_id);
                                AsgarosForumUserGroups::updateUserGroup($category->term_id, $defaultCategory['term_id'], $category->name, $color);
                            }
                        }
                    } else {
                        // Add an example usergroup.
                        $defaultUserGroupName = __('Example Usergroup', 'asgaros-forum');
                        $defaultUserGroup = AsgarosForumUserGroups::insertUserGroup($defaultCategory['term_id'], $defaultUserGroupName, '#2d89cc');
                    }
                }

                update_option('asgarosforum_db_version', 13);
            }

            // Move appearance settings into its own options-array.
            if ($database_version_installed < 14) {
                // Ensure that all options are loaded first.
                $asgarosforum->loadOptions();
                $asgarosforum->appearance->load_options();

                // Build the intersect.
                $appearance_intersect = array_intersect_key($asgarosforum->options, $asgarosforum->appearance->options) + $asgarosforum->appearance->options;

                // Remove keys from old settings.
                $options_cleaned = array_diff_key($asgarosforum->options, $appearance_intersect);

                // Save all options.
                $asgarosforum->appearance->save_options($appearance_intersect);
                $asgarosforum->saveOptions($options_cleaned);

                update_option('asgarosforum_db_version', 14);
            }

            if ($database_version_installed < 19) {
                // Because most of the WordPress users are using a MySQL version below 5.6,
                // we have to set the ENGINE for the post-table to MyISAM because InnoDB doesnt
                // support FULLTEXT before MySQL version 5.6.
                $this->db->query('ALTER TABLE '.$this->tables->topics.' ENGINE = MyISAM;');
                $this->db->query('ALTER TABLE '.$this->tables->topics.' ADD FULLTEXT (name);');

                update_option('asgarosforum_db_version', 19);
            }

            // Create some default content.
            if ($database_version_installed < 20) {
                // Initialize taxonomy first.
                AsgarosForumContent::initialize_taxonomy();

                // Get all categories first.
                $categories = $asgarosforum->content->get_categories(false);

                // Only continue when there are no categories yet.
                if (count($categories) == 0) {
                    // Add an example category.
                    $default_category_name = __('Example Category', 'asgaros-forum');

                    $new_category = wp_insert_term($default_category_name, 'asgarosforum-category');

                    if (!is_wp_error($new_category)) {
                        update_term_meta($new_category['term_id'], 'category_access', 'everyone');
                        update_term_meta($new_category['term_id'], 'order', 1);

                        $default_forum_name = __('First Forum', 'asgaros-forum');
                        $default_forum_description = __('My first forum.', 'asgaros-forum');

                        $asgarosforum->content->insert_forum($new_category['term_id'], $default_forum_name, $default_forum_description, 0, 'dashicons-editor-justify', 1, 0);
                    }
                }

                update_option('asgarosforum_db_version', 20);
            }

            // Add index to topics.parent_id for faster queries.
            if ($database_version_installed < 21) {
                if (!$this->check_index($this->tables->topics, 'parent_id')) {
                    $this->db->query('ALTER TABLE '.$this->tables->topics.' ADD INDEX(parent_id);');
                }

                update_option('asgarosforum_db_version', 21);
            }

            // Use valid default-values for dates.
            if ($database_version_installed < 23) {
                $this->db->query("UPDATE {$this->tables->posts} SET date = '1000-01-01 00:00:00' WHERE date = '0000-00-00 00:00:00';");
                $this->db->query("UPDATE {$this->tables->posts} SET date_edit = '1000-01-01 00:00:00' WHERE date_edit = '0000-00-00 00:00:00';");

                update_option('asgarosforum_db_version', 23);
            }

            // Add index to posts.date for faster queries.
            if ($database_version_installed < 24) {
                if (!$this->check_index($this->tables->posts, 'date')) {
                    $this->db->query('ALTER TABLE '.$this->tables->posts.' ADD INDEX(date);');
                }

                update_option('asgarosforum_db_version', 24);
            }

            // Add index to forums.parent_id for faster queries.
            if ($database_version_installed < 25) {
                if (!$this->check_index($this->tables->forums, 'parent_id')) {
                    $this->db->query('ALTER TABLE '.$this->tables->forums.' ADD INDEX(parent_id);');
                }

                update_option('asgarosforum_db_version', 25);
            }

            // Convert to new role system.
            if ($database_version_installed < 26) {
                // Convert moderators.
                $get_moderators = get_users(array(
                    'fields'            => array('ID'),
                    'meta_query'        => array(
                        array(
                            'key'       => 'asgarosforum_moderator',
                            'compare'   => 'EXISTS'
                        )
                    )
                ));

                if (!empty($get_moderators)) {
                    foreach ($get_moderators as $moderator) {
                        $asgarosforum->permissions->set_forum_role($moderator->ID, 'moderator');
                    }
                }

                delete_metadata('user', 0, 'asgarosforum_moderator', '', true);

                // Convert banned users.
                $get_banned = get_users(array(
                    'fields'            => array('ID'),
                    'meta_query'        => array(
                        array(
                            'key'       => 'asgarosforum_banned',
                            'compare'   => 'EXISTS'
                        )
                    )
                ));

                if (!empty($get_banned)) {
                    foreach ($get_banned as $banned) {
                        $asgarosforum->permissions->set_forum_role($banned->ID, 'banned');
                    }
                }

                delete_metadata('user', 0, 'asgarosforum_banned', '', true);

                update_option('asgarosforum_db_version', 26);
            }

            // We need to save the forum_id in the posts-table to increase performance.
            if ($database_version_installed < 27) {
                $this->db->query("UPDATE {$this->tables->posts} AS p INNER JOIN {$this->tables->topics} AS t ON p.parent_id = t.id SET p.forum_id = t.parent_id;");

                update_option('asgarosforum_db_version', 27);
            }

            // Add index to posts.forum_id for faster queries.
            if ($database_version_installed < 28) {
                if (!$this->check_index($this->tables->posts, 'forum_id')) {
                    $this->db->query("ALTER TABLE {$this->tables->posts} ADD INDEX(forum_id);");
                }

                update_option('asgarosforum_db_version', 28);
            }

            // Add index to posts.(forum_id, id) for faster queries.
            if ($database_version_installed < 29) {
                if (!$this->check_index($this->tables->posts, 'forum_id_2')) {
                    $this->db->query("ALTER TABLE {$this->tables->posts} ADD INDEX(forum_id, id);");
                }

                update_option('asgarosforum_db_version', 29);
            }

            // Save sticky-value in own field.
            if ($database_version_installed < 33) {
                $this->db->query("UPDATE {$this->tables->topics} SET sticky = 1 WHERE status LIKE 'sticky%';");

                update_option('asgarosforum_db_version', 33);
            }

            // Save closed-value in own field.
            if ($database_version_installed < 35) {
                $this->db->query("UPDATE {$this->tables->topics} SET closed = 1 WHERE status LIKE '%closed';");

                update_option('asgarosforum_db_version', 35);
            }

            // Drop old status-column.
            if ($database_version_installed < 36) {
                $this->db->query("ALTER TABLE {$this->tables->topics} DROP COLUMN status;");

                update_option('asgarosforum_db_version', 36);
            }

            update_option('asgarosforum_db_version', $this->db_version);
        }
    }
}

?>
