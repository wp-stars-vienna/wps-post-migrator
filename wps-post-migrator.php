<?php

/**

 * @package WPS Custom Plugins

 */

/*

Plugin Name: WPS Post Migrator

Plugin URI: https://wp-stars.com/

Description: Migrates posts via rest api

Version: 4.1.7

Author: WP Stars, Will Nahmens

Author URI: https://wp-stars.com/

License: GPLv2 or later

Text Domain: wps_post_migrator_v2

*/

global $wps_post_migrator_db_version;
$wps_post_migrator_db_version = '1.1';

function wps_post_migrator_install() {
	global $wpdb;
	global $wps_post_migrator_db_version;

	$table_name = $wpdb->prefix . 'wps_post_migrator';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
        migration_type text NOT NULL,
		last_post_id int NOT NULL,
        last_page_id int NOT NULL,
        last_index int NOT NULL,
        base_url text NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

    $table_name = $wpdb->prefix . 'wps_post_migrator_categories';
	
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
        	original_id int NOT NULL,
		new_id int NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

    dbDelta( $sql );


	add_option( 'wps_post_migrator_install_db_version', $wps_post_migrator_db_version );
}

register_activation_hook( __FILE__, 'wps_post_migrator_install' );

 
function wps_post_migrator_setup_menu(){
    add_menu_page( 'WPS Post Migrator', 'WPS Post Migrator', 'manage_options', 'wps_post_migrator', 'wps_post_migrator_init' );
}
add_action('admin_menu', 'wps_post_migrator_setup_menu');

function wps_post_migrator_enqueue_scripts() {
    wp_enqueue_script( 'wps_post_migrator_script', plugin_dir_url( __FILE__ ) . 'js/wps-post-migrator.js' );
    $migrate_data = array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'siteUrl' => get_site_url(),
    );
    wp_localize_script( 'wps_post_migrator_script', 'migrateData', $migrate_data );

    wp_enqueue_script( 'wps-axios', 'https://cdnjs.cloudflare.com/ajax/libs/axios/0.24.0/axios.min.js');

}
add_action('admin_enqueue_scripts', 'wps_post_migrator_enqueue_scripts');
 
function wps_post_migrator_init(){
    global $wpdb;

    $table = $wpdb->prefix."wps_post_migrator";
    $category_row = $wpdb->get_row("SELECT * FROM $table WHERE migration_type = 'category'");
    $post_row = $wpdb->get_row("SELECT * FROM $table WHERE migration_type = 'post'");
    ?>
    <pre>
        <?= ($category_row) ? $category_row->last_page_id : 1; ?>
        <?= var_dump($post_row); ?>
    </pre>
    <div class="post-migrate-wrapper">

        <h1>WPS Post Migrator</h1>
        <div style="display: none;" class="data-store" data-parent-categories="[]"></div>

        <h3>Category Handler</h3>
        <form method="post" id="category_form" onsubmit="wpsGetCategoriesJson(event)">
            <label for="last_page">Last Page</label>
            <input type="number" id="category_last_page" name="last_page" min="1" value="<?= ($category_row) ? $category_row->last_page_id : 1; ?>" />
            <label for="last_index">Last Index</label>
            <input type="number" id="category_last_index" name="last_index" value="<?= ($category_row) ? $category_row->last_index : ''; ?>" />
            <label for="base_url">Base Url</label>
            <input type="text" id="category_base_url" name="base_url" placeholder="https://mydomain.at/" value="<?= ($category_row) ? $category_row->base_url : ''; ?>" />
            <input type="submit" name="category_form_submit" class="button-primary" value="Start Category Migration" />
        </form>
        <div class="category-alert"></div>
        
        <h3>Post Handler</h3>
        <p>Please note that this plugin will update posts in batches of 100</p>
        <form method="post" id="post_form" onsubmit="wpsGetPostsJson(event)">
            <label for="last_page">Last Page</label>
            <input type="number" id="post_last_page" name="last_page" min="1" value="<?= ($post_row) ? $post_row->last_page_id : 1; ?>" />
            <label for="last_index">Last Index</label>
            <input type="number" id="post_last_index" name="last_index" value="<?= ($post_row) ? $post_row->last_index : ''; ?>" />
            <label for="base_url">Base Url</label>
            <input type="text" id="post_base_url" name="base_url" placeholder="https://mydomain.at/" value="<?= ($post_row) ? $post_row->base_url : ''; ?>" />
            <input type="submit" name="post_form_submit" class="button-primary" value="Start Post Migration" />
        </form>
        <div class="post-alert"></div>

    </div>
    
    <?php
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'wps_routes/v1', '/wps_migrate_categories', array(
        'methods' => array('POST', 'GET'),
        'callback' => 'wps_migrate_categories',
        'permission_callback' => function() {
            return current_user_can( 'administrator' ); // you can only use this route if you're an admin
        }
    ));
});

function wps_migrate_categories(WP_REST_Request $req ) {
    $body = $req->get_body();
    $body = json_decode($body);

    global $wpdb;
    // create new array with each individual post data

    $categories = $body->categories;

    $index = 0;
    $inserted_categories = array();
    foreach ($categories as $category) {

        // check if term already exists
        if(!term_exists( $category->slug, 'category' )) {
            $has_parent = $category->parent;

            if($category->parent != 0) {
                // create sub category
                $new_category = wp_insert_term(
                    $category->name, 
                    'category', 
                    array(
                        // what to use in the url for term archive
                        'slug' => $category->slug,  
                        'parent'=> $body->parent
                    )
                );

                // insert new data as row in categories table
                $cat_table = $wpdb->prefix.'wps_post_migrator_categories';
                $cat_data = array(
                    'original_id' => $category->id,
                    'new_id' => $new_category['term_id']
                );

                $wpdb->insert($cat_table,$cat_data);

            } else {
                // create parent category
                $new_category = wp_insert_term(
                    $category->name, 
                    'category', 
                    array(
                        // what to use in the url for term archive
                        'slug' => $category->slug,  
                    )
                );

                // insert new data as row in categories table
                // need to check in the future if there is an entry already
                $cat_table = $wpdb->prefix.'wps_post_migrator_categories';
                $cat_data = array(
                    'original_id' => $category->id,
                    'new_id' => $new_category['term_id']
                );

                $wpdb->insert($cat_table,$cat_data);
            }
    
            if(is_wp_error( $new_category )) {
                return new WP_Error( 
                    'error_inserting', 
                    'Error Inserting Category', 
                    array( 
                        'status' => 201, 
                        'message' => $new_category 
                    ) 
                );
            }

            array_push($inserted_categories, $new_category);
        }

        $index++;
    }

    $res_obj = new stdClass();
    $res_obj->inserted_categories = $inserted_categories;
    $res_obj->posted_categories = $body->categories;

    $res = new WP_REST_Response($res_obj);
    $res->set_status(200);
    return $res;
}
 

add_action( 'rest_api_init', function () {
    register_rest_route( 'wps_routes/v1', '/wps_migrate_posts', array(
        'methods' => array('POST', 'GET'),
        'callback' => 'wps_migrate_posts',
        'permission_callback' => function() {
            return current_user_can( 'administrator' ); // you can only use this route if you're an admin
        }
    ));
});

function wps_migrate_posts(WP_REST_Request $req ) {

    $body = $req->get_body();
    $body = json_decode($body);

    global $wpdb;
    // create new array with each individual post data

    $posts = $body->posts;

    $index = 0;
    $inserted_posts = array();
    foreach ($posts as $post) {
        // check for duplicates in the titles maybe?

        $table = $wpdb->prefix.'wps_post_migrator';
        $data = array(
            'migration_type' => 'post',
            'last_post_id' => $post->id,
            'last_page_id' => $body->page,
            'last_index' => $index,
            'base_url' => $body->base_url,
        );

        $rows = $wpdb->get_results("SELECT * FROM $table");

        if(count($rows) > 0) {
            $where = array(
                'ID' => 0,
            );
        
            $wpdb->update($table,$data, $where);

        } else {
            $wpdb->insert($table,$data);
            $insert_id = $wpdb->insert_id;
        }

        $cat_table = $wpdb->prefix.'wps_post_migrator_categories';
        $new_cats = array();
        $original_cats = $post->categories;
        foreach ($original_cats as $key => $cat) {
            $new_cat = $wpdb->get_row("SELECT new_id FROM $cat_table WHERE original_id = $cat");
            array_push($new_cats, $new_cat->new_id);
        }

        $new_post = array();
        $new_post['post_title']    = $post->title->rendered;
        $new_post['post_content']  = $post->content->rendered;
        $new_post['post_status']   = $post->status;
        $new_post['post_author']   = $post->author;
        $new_post['post_category'] = $new_cats;
        $new_post['post_date'] = $post->date;
        $new_post['post_excerpt'] = $post->excerpt->rendered;
        $new_post['post_slug'] = $post->slug;
        $new_post['post_type'] = $post->type;
        $new_post['post_modified'] = $post->modified;

        $inserted_post = wp_insert_post( $new_post );

        if(is_wp_error( $inserted_post )) {
            return new WP_Error( 
                'error_inserting', 
                'Error Inserting Post', 
                array( 
                    'status' => 501, 
                    'message' => $inserted_post 
                ) 
            );

        }

        array_push($inserted_posts, $new_post);
        $index++;
    }

    $res_obj = new stdClass();
    $res_obj->inserted_posts = $inserted_posts;

    $res = new WP_REST_Response($res_obj);
    $res->set_status(200);

    return $res;

}
 
