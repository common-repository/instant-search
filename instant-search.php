<?php 
    if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
    }
?>
<?php
/**
 * Plugin Name: Instant Search
 * Plugin URI: https://instant-search.net
 * Description: A WordPress search plugin with live and voice search.
 * Version: 1.1.0
 * Author: webmaster85
 * Author URI: https://www.marincas.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

function instant_search_form() {
    ob_start(); 
    include plugin_dir_path(__FILE__) . 'searchform.php'; 
    $form = ob_get_clean(); 
    return $form; 
}
add_shortcode('instant_search', 'instant_search_form'); 
function instant_search_scripts() {
    wp_enqueue_script('jquery'); 
    wp_enqueue_script('instant_search', plugins_url('instant_search.js', __FILE__), array('jquery'), '1.0', true); 
    wp_enqueue_style('instant_search', plugins_url('instant_search.css', __FILE__));
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css');
    wp_localize_script('instant_search', 'instant_search', array(
        'endpoint_url' => home_url('/wp-json/instant-search/v1/search'), 
        'display_style' => get_option('instant_search_display_style', 'list'),
        'search_method' => get_option('instant_search_method', 'overlay')
    ));
}
add_action('wp_enqueue_scripts', 'instant_search_scripts'); 
function wpdocs_enqueue_custom_admin_style() {
    wp_register_style( 'custom_wp_admin_css', plugin_dir_url( __FILE__ ) . 'settings.css', false, '1.0.0' );
    wp_enqueue_style( 'custom_wp_admin_css' );
}
add_action( 'admin_enqueue_scripts', 'wpdocs_enqueue_custom_admin_style' );

add_action('rest_api_init', function() {
    register_rest_route('instant-search/v1', '/search', array(
        'methods' => 'POST',
        'callback' => 'instant_search_ajax',
        'permission_callback' => '__return_true',
    ));
});
function instant_search_ajax(WP_REST_Request $request) {
    $query = sanitize_text_field($request->get_param('query')); 
    $post_types = get_option('instant_search_post_types', array('post', 'page'));
    $min_length = 3;	
    if (strlen($query) < $min_length) {
        return new WP_REST_Response('<h2>' . esc_html__('Please enter at least ' . $min_length . ' characters.') . '</h2>', 200);
    }	
    log_search_query($query); 
    $results_per_page = get_option('instant_search_results_per_page', 10);	
    $search_results = get_posts(array(
        's' => $query,
        'post_type' => $post_types,
        'posts_per_page' => $results_per_page, 
    ));
    $output = '';    
    if (!empty($search_results)) {
        foreach ($search_results as $post) {
            $thumbnail = get_the_post_thumbnail_url($post->ID, 'thumbnail');
            $output .= '<div class="search-result">';
            if ($thumbnail) {
                $output .= '<img src="' . esc_url($thumbnail) . '" alt="' . esc_attr(get_the_title($post)) . '">';
            }
            $output .= '<h2><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h2>';
            $output .= '<p>' . esc_html(substr(get_the_excerpt($post), 0, 50)) . '</p>';
            $output .= '</div>';
        }       
        $output .= '<div class="view-all"><a href="' . esc_url(home_url('/?s=' . urlencode($query))) . '" class="button">View All</a></div>';
    } else {
        $output .= '<h2>' . esc_html__('No results found.') . '</h2>';
    }
    return new WP_REST_Response($output, 200);
}
function instant_search_menu() {
    add_menu_page('Instant Search Settings', 'Instant Search', 'manage_options', 'instant_search', 'instant_search_options', 'dashicons-search', 20);
}
add_action('admin_menu', 'instant_search_menu'); 
function instant_search_custom_styles() {
    $search_form_width = get_option('instant_search_form_width', '300px'); 
    $search_form_width2 = get_option('instant_search_form_width2', '300px'); 
    echo '<style>
        .search-wrapper, #searchform {
            width: ' . esc_attr($search_form_width) . '; 
        }
        input#s2, #searchform2 {
            width: ' . esc_attr($search_form_width2) . ';
        }
    </style>';
}
add_action('wp_head', 'instant_search_custom_styles'); 
function search_by_sku( $search, $query_vars ) {
    global $wpdb;
    if(isset($query_vars->query['s']) && !empty($query_vars->query['s'])){
        $args = array(
            'posts_per_page'  => -1,
            'post_type'       => 'product',
            'meta_query' => array(
                array(
                    'key' => '_sku',
                    'value' => $query_vars->query['s'],
                    'compare' => 'LIKE' 
                )
            )
        );
        $posts = get_posts($args);
        if(empty($posts)) return $search; 
        $get_post_ids = array();
        foreach($posts as $post){
            $get_post_ids[] = $post->ID;  
        }
        if(sizeof( $get_post_ids ) > 0 ) {
            $search = str_replace( 'AND (((', "AND ((({$wpdb->posts}.ID IN (" . implode( ',', $get_post_ids ) . ")) OR (", $search); 
        }
    }
    return $search; 
}
add_filter( 'posts_search', 'search_by_sku', 999, 2 ); 
add_action('admin_post_flush_search_queries_action', 'flush_search_queries');
function flush_search_queries() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'instant_search_queries';
    $wpdb->query("TRUNCATE TABLE $table_name"); 
    wp_redirect(admin_url('admin.php?page=instant_search')); 
    exit;
}
function instant_search_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'instant_search_queries'; 
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        query varchar(255) NOT NULL,
        count mediumint(9) NOT NULL DEFAULT 1,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql); 
}
register_activation_hook(__FILE__, 'instant_search_activate');
function instant_search_check_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'instant_search_queries';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        instant_search_activate();
    }
}
add_action('plugins_loaded', 'instant_search_check_table');
function log_search_query($query) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'instant_search_queries';   
    $query = sanitize_text_field($query);
    if (empty($query)) {
        return; 
    }   
    $existing_query = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE query = %s", $query));
    if ($existing_query) {       
        $wpdb->update(
            $table_name,
            array('count' => $existing_query->count + 1),
            array('id' => $existing_query->id)
        );
    } else {      
        $wpdb->insert(
            $table_name,
            array('query' => $query, 'count' => 1) 
        );
    }
}
function get_top_searches($limit = 20) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'instant_search_queries';

    return $wpdb->get_results($wpdb->prepare("SELECT query, count FROM $table_name ORDER BY count DESC LIMIT %d", $limit));
}
function instant_search_options() {    
    if (isset($_POST['save_changes'])) {
        if (!isset($_POST['instant_search_nonce']) || !wp_verify_nonce($_POST['instant_search_nonce'], 'instant_search_save')) {
            echo '<div class="error"><p>' . esc_html__('Nonce verification failed. Please try again.', 'text-domain') . '</p></div>';
            return;
        }
        echo '<div class="updated"><p>' . esc_html__('Settings saved successfully.', 'text-domain') . '</p></div>';
    }    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.')); 
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['post_types'])) {
            $post_types = array_map('sanitize_text_field', $_POST['post_types']);
            update_option('instant_search_post_types', $post_types);
        }
        if (isset($_POST['display_style'])) {
            $display_style = sanitize_text_field($_POST['display_style']);
            update_option('instant_search_display_style', $display_style);
        }
        if (isset($_POST['search_placeholder'])) {
            $search_placeholder = sanitize_text_field($_POST['search_placeholder']);
            update_option('instant_search_placeholder', $search_placeholder);
        }
        if (isset($_POST['search_form_width'])) {
            $search_form_width = sanitize_text_field($_POST['search_form_width']);
            update_option('instant_search_form_width', $search_form_width);
        }
        if (isset($_POST['search_form_width2'])) {
            $search_form_width2 = sanitize_text_field($_POST['search_form_width2']);
            update_option('instant_search_form_width2', $search_form_width2);
        }
        if (isset($_POST['search_method'])) {
            $search_method = sanitize_text_field($_POST['search_method']);
            update_option('instant_search_method', $search_method);
        }
        if (isset($_POST['enable_voice_search'])) {
            update_option('instant_search_enable_voice_search', '1'); 
        } else {
            update_option('instant_search_enable_voice_search', '0'); 
        }
        if (isset($_POST['results_per_page'])) {
            $results_per_page = sanitize_text_field($_POST['results_per_page']);
            update_option('instant_search_results_per_page', $results_per_page); 
        }
        if (isset($_POST['flush_search_queries'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'instant_search_queries';
            $wpdb->query("TRUNCATE TABLE $table_name"); 
            echo '<div class="updated"><p>' . esc_html__('Search queries have been flushed.') . '</p></div>';
        }
    }    
    $post_types = get_option('instant_search_post_types', array('post', 'page'));
    $display_style = get_option('instant_search_display_style', 'list');
    $search_placeholder = get_option('instant_search_placeholder', 'What are we searching for today?');
    $all_post_types = get_post_types(array('public' => true), 'names');
    $search_form_width = get_option('instant_search_form_width', '50%');
    $search_form_width2 = get_option('instant_search_form_width2', '50%');
    $search_method = get_option('instant_search_method', 'overlay'); 
    $enable_voice_search = get_option('instant_search_enable_voice_search', '1'); 
    $results_per_page = get_option('instant_search_results_per_page', '10');        
    $top_searches = get_top_searches(20);     
    ?>    
    <div class="wrapper">
    <h1 class="bigtitle">Instant Search Plugin Settings <button id="toggle-ads-button">Show Ads</button></h1>    
        <div id="toggle-ads">
            <script>
            jQuery(document).ready(function() {
                jQuery("#toggle-ads-button").click(function() {
                    jQuery("#toggle-ads").toggle();
                    if (jQuery("#toggle-ads").is(":visible")) {
                        jQuery("#toggle-ads-button").text("Hide Ads").css("background-color", "transparent");;
                    } else {
                        jQuery("#toggle-ads-button").text("Show Ads").css("background-color", "transparent");
                    }
                });
            });    
            </script>                
            <div class="ads-images">    
            <h3>Want to create stunning websites with the assistance of AI?</h3>
            <a href="https://www.elegantthemes.com/affiliates/idevaffiliate.php?id=47480" target="_blank" rel="nofollow">
            <img style="border:0px" src="<?php echo esc_url(plugins_url('/banner/et-affiliate.png', __FILE__)); ?>" width="570" height="100" alt="">
            </a>        
            <h3>Check out the best E-Commerce theme:</h3>
            <a href="https://themeforest.net/item/rey-multipurpose-woocommerce-theme/24689383" target="_blank" rel="nofollow">
            <img style="border:0px" src="<?php echo esc_url(plugins_url('/banner/rey.png', __FILE__)); ?>" width="570" height="100" alt="">
            </a>
            <h3>Get great hosting:</h3>
            <a href="https://hosterion.ro/client/aff.php?aff=160" target="_blank" rel="nofollow">
            <img style="border:0px" src="<?php echo esc_url(plugins_url('/banner/hosterion-affiliate.png', __FILE__)); ?>" width="570" height="100" alt="">
            </a>    
            </div>        
        </div>      
     <form method="post" action="">  
        <?php wp_nonce_field('instant_search_save', 'instant_search_nonce'); ?>
      <div class="settings-wrapper">       
        <script>        
            document.addEventListener('click', function (e) {
                e = e || window.event;
                var target = e.target || e.srcElement;

                if (target.hasAttribute('data-toggle') && target.getAttribute('data-toggle') == 'modal_settings') {
                    if (target.hasAttribute('data-target')) {
                        var m_ID = target.getAttribute('data-target');
                        document.getElementById(m_ID).classList.add('open_settings');
                        e.preventDefault();
                    }
                }
                if ((target.hasAttribute('data-dismiss') && target.getAttribute('data-dismiss') == 'modal_settings') || target.classList.contains('modal_settings')) {
                    var modal = document.querySelector('[class="modal_settings open_settings"]');
                    modal.classList.remove('open_settings');
                    e.preventDefault();
                }
            }, false);

        </script>        
        <script>
			function confirmFlush() {
			if (confirm('Are you sure you want to flush the search queries? This action cannot be undone.')) {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = '<?php echo esc_url(admin_url('admin-post.php')); ?>';
            var inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'action';
            inputAction.value = 'flush_search_queries_action';
            form.appendChild(inputAction);
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>    
            <div class="is-box">
               <div class="is-title">
                  <h3>Search method
                  <span data-target="search_method_settings" data-toggle="modal_settings">&#63;</span>
                  </h3> 
                  </div> 
               <div class="is-content">     
                  <select style="max-width:100%;" name="search_method">
                   <option value="overlay"<?php if ($search_method == 'overlay') echo ' selected'; ?>>Overlay</option>
                   <option value="inline"<?php if ($search_method == 'inline') echo ' selected'; ?>>Inline</option>
                 </select>
               </div>
            </div>
            <div id="search_method_settings" class="modal_settings">
                <div class="modal-window">                
                    <h3>Search method</h3>
                    <p>Select how do you want the form to behave, inline to display the search results right below the form, or overlay to display them in a large modal, all over the screen. When the overlay method is selected, the live voice search microphone icon will disappear from the inline form, and be present just in the overlay modal.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>            
                <div class="is-box">
                   <div class="is-title">
                      <h3>Display
                       <span data-target="display_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">     
                        <select style="max-width:100%;" name="display_style">
                            <option value="list"<?php if ($display_style == 'list') echo ' selected'; ?>>List</option>
                            <option value="grid"<?php if ($display_style == 'grid') echo ' selected'; ?>>Grid</option>
                        </select>    
                   </div> 
                </div>
            <div id="display_settings" class="modal_settings">
                <div class="modal-window">                
                    <h3>Display settings</h3>
                    <p>The grid is perfect for desktops, offering a visually appealing layout, while the list view is ideal for small screens like phones, ensuring better readability and easier navigation.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>                
            <div class="is-box">
                <div class="is-title">
                    <h3>Number of results
                        <span data-target="results_per_page_settings" data-toggle="modal_settings">&#63;</span>
                    </h3>
                </div>
                <div class="is-content" style="text-align:left;">           
                   
            <input type="text" style="min-width:250px;" name="results_per_page" value="<?php echo esc_attr($results_per_page); ?>" placeholder="e.g., 5, 10" min="1" />  
                </div>
            </div>            
            <div id="results_per_page_settings" class="modal_settings">
                <div class="modal-window">                
                    <h3>Number of results</h3>
                    <p>Control the number of AJAX live search results. If there are more results than the number set here, a "View All" button will be displayed to redirect the visitors to the search results page. Leave blank for all. Keep in mind this number is also controlled by WordPress in <strong>Settings -> Reading -> Syndication feeds</strong>.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>            
             <div class="is-box">
                <div class="is-title">
                    <h3>Voice Search
                        <span data-target="voice_search_settings" data-toggle="modal_settings">&#63;</span>
                    </h3>
                </div>
                <div class="is-content" style="text-align:left;">
                    <label>
                        <input type="checkbox" name="enable_voice_search" value="1" <?php checked($enable_voice_search, '1'); ?> />
                        Enabled
                    </label>
                </div>
            </div>                
                <div id="voice_search_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>Voice search</h3>
                    <p>
                    Enable or disable the voice search microphone icon.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>    
            <div class="is-box">
               <div class="is-title">
                  <h3>Inline width
                   <span data-target="inline_width_settings" data-toggle="modal_settings">&#63;</span>
                  </h3>
               </div> 
               <div class="is-content" style="text-align:left;">    
                <input type="text" style="min-width:250px;" name="search_form_width2" value="<?php echo esc_attr($search_form_width2); ?>" placeholder="e.g., 50%, 300px, 30vh" />    
               </div>  
            </div>                     
            <div id="inline_width_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>Inline width</h3>
                    <p>
                    Customize the width of the inline form. By default it's set to 300px, but you can change it to any CSS unit that suits your needs. You could use 50% for a responsive design, 300px for a fixed width, or 30vh to make it proportional to the viewport height. The inline form will span as long as the wrapper where the shortcode is placed allows it. For example, if you're using page builders like Divi Builder or Elementor, if the shortcode is placed in a 2/3 column, the search results will be displayed in a narrow space if this setting is in percentage units. However, you can override that space with a width set in pixels.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>                              
                 <div class="is-box">
                   <div class="is-title">
                      <h3>Overlay width
                      <span data-target="overlay_width_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div> 
                   <div class="is-content" style="text-align:left;">        
                <input type="text" style="min-width:250px;" name="search_form_width" value="<?php echo esc_attr($search_form_width); ?>" placeholder="e.g., 50%, 300px, 30vh" />        
                   </div>
                </div>                 
                <div id="overlay_width_settings" class="modal_settings">
                    <div class="modal-window">    
                        <h3>Overlay width</h3>
                        <p>
                        Customize the width of the overlay form. By default it's set to 300px, but you can change it to any CSS unit that suits your needs. You could use 50% for a responsive design, 300px for a fixed width, or 30vh to make it proportional to the viewport height.
                        </p>
                        <button class="close_settings" data-dismiss="modal_settings">Close</button>
                    </div>
                </div>
            <div class="is-box">
                   <div class="is-title">
                      <h3>Placeholder text
                      <span data-target="placeholder_text_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">       
                       <input type="text" style="min-width:250px;" name="search_placeholder" value="<?php echo esc_attr($search_placeholder); ?>" />
                   </div>
                </div>
            <div id="placeholder_text_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>Placeholder text</h3>
                    <p>
                    Set a personalized message that will be displayed inside the search form. The default is usually "Search..." but you have the flexibility to change it. For example, you could use "What are we searching for today?" or anything else that suits your needs.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>
                <div class="is-box">
                   <div class="is-title">
                      <h3>Post types
                      <span data-target="post_types_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">     
                     <?php foreach ($all_post_types as $post_type): ?>
                            <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type); ?>"<?php if (in_array($post_type, $post_types)) echo ' checked'; ?> /> <?php echo esc_html($post_type); ?><br />
                        <?php endforeach; ?>
                   </div>
                </div>
            <div id="post_types_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>Post types</h3>
                    <p>
                    Choose the types of posts you'd like the form to display results for. Feel free to select multiple options.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>
			<div class="is-box">
                   <div class="is-title">
                      <h3>Analytics <span class="notify-badge">NEW</span>
                      <span data-target="analytics_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">                    
                    <span class="is-view-analytics" data-target="analytics_settings2" data-toggle="modal_settings">View</span>
                   </div>
             </div>
            <div id="analytics_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>Analytics</h3>            
              <h2>Top 20 Searched Words On Your Website</h2>
              <p>
              This option creates a table in your WordPress database, called <a target="_blank" href="<?php echo esc_url(plugins_url('/banner/database_queries_table.jpg', __FILE__)); ?>"><i>instant_search_queries</i></a>
              (don't worry about this if you're not tech savvyy, it's really not important). It logs top 20 words your visitors search the most on your website and the entries are rewritten, so no need to worry about the table getting bloated in time. So for example let's say your website sells shoes but your visitors search the most after the word "dress", a product that doesn't exist on your website. They don't see any results, but you know they search for dresses because the word shows up here in most searched, so you might want to add that product.
              Be sure to specify this in your website's terms, visitors need to know their searches might be stored to improve the search experience. It's in beta tests, currently working to optimize the results.
              </p>            
                <button class="close_settings" data-dismiss="modal_settings">Close</button>               
               </div>
            </div>             
                <div id="analytics_settings2" class="modal_settings">
                <div class="modal-window">    
                    <h3>Analytics</h3>            
              <h2>Top 20 Searched Words On Your Website</h2>              
                <ul>
                    <?php foreach ($top_searches as $search) : ?>
                        <li><?php echo esc_html($search->query) . ' - ' . esc_html($search->count); ?> times</li>
                    <?php endforeach; ?>
                </ul>
          <button type="button" class="donate-button" id="flush-search-queries" onclick="confirmFlush()">Clear the list</button>            
          <button class="close_settings" data-dismiss="modal_settings">Close</button>             
                </div>
            </div>            
            <div class="is-box">
               <div class="is-title">
                  <h3>Shortcode
                  <span data-target="shortcode_settings" data-toggle="modal_settings">&#63;</span>
                  </h3>
               </div>
               <div class="is-content" style="text-align:left;">      
             <h3 id="myText">[instant_search]</h3>		 
               </div>
			</div>             
            <div id="shortcode_settings" class="modal_settings">
                <div class="modal-window">    
                    <h3>The shortcode</h3>
                    <p>
                    Use the shortcode to display a search form anywhere. Personally, I find it works best when placed in the website's header. It optimizes user experience and navigation, as it's a prime location for visitors to initiate searches, thus enhancing overall site usability.
                    </p>
                    <button class="close_settings" data-dismiss="modal_settings">Close</button>
                </div>
            </div>
                 <div class="is-box">
                   <div class="is-title">
                      <h3>Donate
                      <span data-target="donate_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">       
                 <h3><a class="donate-button" href="https://buymeacoffee.com/webmaster85" target="_blank">Donate</a></h3>        
                   </div>
                </div>                          
                <div id="donate_settings" class="modal_settings">
                    <div class="modal-window">    
                        <h3>Donate</h3>
                        <p>
                        I use the donations to improve the plugin. That could mean a variety of things. I mainly buy web development courses that let me upgrade my skills to understand the whole environment better, how the search works in-depth, and see the big picture.
                        </p>
                        <button class="close_settings" data-dismiss="modal_settings">Close</button>
                    </div>
                </div>                  
				<div class="is-box">
                   <div class="is-title">
                      <h3>Review
                      <span data-target="review_settings" data-toggle="modal_settings">&#63;</span>
                      </h3>
                   </div>
                   <div class="is-content" style="text-align:left;">       
                 <h3><a class="review-button donate-button" href="https://wordpress.org/support/plugin/instant-search/reviews/#new-post" target="_blank">Review </a></h3>       
                   </div>
                </div>                          
                <div id="review_settings" class="modal_settings">
                    <div class="modal-window">    
                        <h3>Review</h3>
                        <p>
                        If you have a moment, I’d love it if you could share a quick review on the plugin's page if you find it useful. Your feedback helps the plugin and a 5-star review would mean a lot there and grow the plugin in popularity! Thank you so much! 😄
                        </p>
                        <button class="close_settings" data-dismiss="modal_settings">Close</button>
                    </div>
                </div>   				
            <h3>
                Test <a href="https://instant-search.net/live-voice-search/" target="_blank"> live voice search </a> and <a href="https://instant-search.net/live-search/" target="_blank"> live search</a>.
            </h3>        
     </div>      
     <p class="submit-changes">
                <input type="submit" class="save-changes" value="Save Changes" />
         </p>          
      </form>   
    </div>
<?php
} 
?>