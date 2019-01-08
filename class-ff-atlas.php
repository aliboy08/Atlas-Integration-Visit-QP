<?php
class FF_Atlas
{
    
    public $api_key = '8b2b492310024a739e2ce02dc76a5ba2';

    private $post_type, $post_id, $product_id, $product_data, $product_data_all;

    public $force_update = false, $dont_update_cache = false;

    public $size = 100;
    public $page = 1;
    public $locations = 'Queanbeyan,Queanbeyan%20East,Queanbeyan%20West,The%20Ridgeway,Royalla,Tralee,Googong,Karabar,Jerrabomberra,Greenleigh,Environa,Burra,Royalla,Tinderry,Urila,Williamsdale,Braidwood,Araluen,Ballalaba,Bendoura,Berlang,Bombay,Budawang,Charleys%20Forest,Corang,Durran%20Durra,Farringdon,Jembaicumbene,Kindervale,Krawarree,Larbert,Majors%20Creek,Marlowe,Monga,Mongarlowe,Neringla,Nerriga,Northangera,Oallen,Reidsdale,Snowball,Tomboye,Warri,Wog%20Wog,Wyanbene,Bungendore,Boro,Bywong,Carwoola,Currawang,Manar,Mount%20Fairy,Mulloon,Sutton,Tarago,Wamboin,Captains%20Flat,Forbes%20Creek,Harolds%20Cross,Hereford%20Hall,Hoskinstown,Jerrabattgulla,Jinden,Primrose%20Valley,Rossi,Yarrow,Canberra';
    public $states = 'NSW,QLD';
    public $output = 'xml';
    
    // Build Atlas Query Args
    public function build_args($post_type) {

        switch( $post_type ) {
            case 'events' :
                $type = 'EVENT';
                break;
            case 'see_and_do' :
                $type = 'ATTRACTION,DESTINFO,GENSERVICE,HIRE,INFO,JOURNEY,TOUR,TRANSPORT';
                break;
            case 'taste' :
                $type = 'RESTAURANT';
                break;
            case 'stay' :
                $type = 'ACCOMM';
                break;
        }

        $query_args = array(
            'key'   => $this->api_key,
            'ct'    => $this->locations,
            'size'  => $this->size,
            'pge'   => $this->page,
            'st'    => $this->states,
            'out'   => $this->output,
            'cats'  => $type,
        );

        return $query_args;
    }

    public function set_size($size){
        $this->size = $size;
    }
    public function set_page($page){
        $this->page = $page;
    }
    public function set_location($location){
        $this->location = $location;
    }
    public function set_states($states){
        $this->states = $states;
    }
    public function set_force_update($flag){
        $this->force_update = $flag;
    }
    public function set_dont_update_cache($flag){
        $this->dont_update_cache = $flag;
    }

    // Get last update/check date & time
    public function get_last_update($post_type) {
        $key = 'ff_atlas_last_update_'. $post_type;
        $option = get_option($key);
        if( !is_array( $option ) || !$option ) return false;
        
        $last_update = '';
        if( isset($option['date']) ) {
            $last_update .= $option['date'];
        }
        if( isset($option['time']) ) {
            $last_update .= ' '. $option['time'];
        }

        return $last_update;
    }

    // Run Atlas Query
    public function run_query($args, $return_type) {
        $query_url = 'http://atlas.atdw-online.com.au/api/atlas/products?'. http_build_query($args);
        return $this->curl( $query_url, $return_type );
    }

    // Run curl, return json or xml format
    public function curl($url, $return_type = 'xml') {
        $ch = curl_init();
        curl_setopt ($ch, CURLOPT_URL, $url);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);

        if( $return_type == 'xml' ) {
            return simplexml_load_string($result);
        }
        else if( $return_type == 'json' ) {
            
            $json_decoded = json_decode( preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $result), true );

            // switch (json_last_error()) {
            //     case JSON_ERROR_NONE:
            //         echo ' - No errors';
            //     break;
            //     case JSON_ERROR_DEPTH:
            //         echo ' - Maximum stack depth exceeded';
            //     break;
            //     case JSON_ERROR_STATE_MISMATCH:
            //         echo ' - Underflow or the modes mismatch';
            //     break;
            //     case JSON_ERROR_CTRL_CHAR:
            //         echo ' - Unexpected control character found';
            //     break;
            //     case JSON_ERROR_SYNTAX:
            //         echo ' - Syntax error, malformed JSON';
            //     break;
            //     case JSON_ERROR_UTF8:
            //         echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
            //     break;
            //     default:
            //         echo ' - Unknown error';
            //     break;
            // }
            
            return $json_decoded;

        }
    }

    // Update / Create Posts
    public function update_and_create($post_type) {
        
        $this->post_type = $post_type;

        $args = $this->build_args($post_type);

        $last_update = $this->get_last_update($post_type);
        if( $last_update ) {
            // Only query posts after last check
            if( !$this->force_update ) {
                $args['delta'] = $last_update;
            }
        }

        debug_only($args);
        
        $data = $this->run_query($args, 'xml');

        //debug_only((string)$data->number_of_results);
        //debug_only(count($data->products->product_record));

        $last_update_date_time = '';
        $last_update_timestamp = 0;
        
        // Loop
        $products = $data->products->product_record;
        foreach( $products as $product_data ) {
            $this->product_data = $product_data;
            $product_id = (string)$product_data->product_id;
            $product_name = wp_strip_all_tags( (string)$product_data->product_name );
            debug_only($product_name . ' | '. $product_id);
            
            $post_id = false;
            //$post_exists =  get_page_by_title($product_name, OBJECT, $post_type);
            $post_exists =  $this->check_post_exist($product_id, $post_type);
            if( $post_exists ) {
                // Post Exists
                debug_only('Post Exists');
                $post_id = $post_exists;

                $post_title = get_the_title($post_id);
                if( $post_title != $product_name ) {
                    // Title changed, update title and slug
                    wp_update_post(
                        array (
                            'ID' => $post_id,
                            //'post_name' => sanitize_title($product_name),
                            'post_title' => $product_name
                        )
                    );
                }
                
                //$post_id = $post_exists->ID;
                
            } else {
                // Post Does not exist, create post
                debug_only('Post Does not exist, create post');
                $post_args = array(
                    'post_type'		=> $post_type,
                    'post_title'	=> $product_name,
                    'post_content'  => (string)$product_data->product_description,
                    'post_status'	=> 'publish',
                );
                
                $post_id = wp_insert_post( $post_args );
            }

            if( !$post_id ) continue;

            // Caching
            $current_update_date_time = $this->update_post_data($post_id, $product_data);
            if( $current_update_date_time ) {
                //debug_only($current_update_date_time);
                $current_update_timestamp = strtotime($current_update_date_time);
                if( $current_update_timestamp > $last_update_timestamp ) {
                    $last_update_date_time = $current_update_date_time;
                    $last_update_timestamp = $current_update_timestamp;
                }
            }
        }

        if( $last_update_date_time ) {
            //debug_only('Last update time = '. $last_update_date_time);
            $temp = new DateTime($last_update_date_time);
            $temp->modify("+1 second"); // 1 second after the last update
            $last_update = array(
                'date' => $temp->format('Y-m-d'),
                'time' => $temp->format('H:i:s'),
            );
            //debug_only($last_update);
            if( !$this->dont_update_cache ) {
                update_option('ff_atlas_last_update_'. $post_type, $last_update);
            }
        }
        
    }

    // Check inactive posts
    public function check_inactive_posts($post_type){
        $this->size = 200;
        $args = $this->build_args($post_type);
        //debug_only($args);
        $data = $this->run_query($args, 'xml');

        $product_ids = array();
        $inactive_posts = array();
        
        // Query products
        $products = $data->products->product_record;
        foreach( $products as $product_data ) {
            $product_id = (string)$product_data->product_id;
            $product_name = wp_strip_all_tags( (string)$product_data->product_name );
            //debug_only($product_name);
            array_push($product_ids, $product_id);
        }

        // Query Posts
        $posts = get_posts(array(
            'post_type' => $post_type,
            'showposts' => -1,
            'post_status' => 'publish',
            'no_found_rows' => true,
            'fields' => 'ids',
        ));
        if( $posts ) {
            //debug_only('NUMBER OF POSTS = '. count($posts));
            foreach( $posts as $p_id ) {
                $product_id = get_post_meta($p_id, 'product_id', true);
                //debug_only($p->post_title . ' | '. $product_id);
                if( !in_array($product_id, $product_ids) ) {
                    // If post is not on the products list, post is inactive
                    //debug_only('INACTIVE');
                    array_push($inactive_posts, $p_id);
                }
            }
        }
        
        //debug_only('$product_ids');
        //debug_only($product_ids);
        //debug_only('$inactive_posts');
        //debug_only($inactive_posts);

        // Set inactive posts as draft / DELETE
        if( $inactive_posts ) {
            foreach( $inactive_posts as $post_id ) {
                //wp_update_post(array('ID' => $post_id, 'post_status' => 'draft' ));
                wp_delete_post( $post_id, true );
            }
        }
    }
    
    // Update/Save post data
    public function update_post_data($post_id, $product_data) {

        $post_type = $this->post_type;

        $product_id = (string)$product_data->product_id;

        update_field('product_id', $product_id, $post_id);
        
        $product_data_all = $this->get_all_product_data($product_id, 'xml');

        $this->product_data_all = $product_data_all;
        
        // Common data shared by all post types
        $update_meta = $this->common_data( $post_id, $product_data_all );

        // Post type specific updates
        switch( $this->post_type ) {
            case "events" :
                $update_meta = $this->specific_data_events( $post_id, $update_meta );
                break;
            case "see_and_do" : 
                $update_meta = $this->specific_data_see_and_do( $post_id, $update_meta );
                break;
            case "taste" : 
                $update_meta = $this->specific_data_taste( $post_id, $update_meta );
                break;
            case "stay" : 
                $update_meta = $this->specific_data_stay( $post_id, $update_meta );
                break;
        }

        // Region - for filtering
        $city = (string)$product_data_all->product_distribution->product_record->city_name;
        $this->set_post_term_with_fallback($post_id, $city, 'region', true);

        // Process Images & Videos
	    $media = $product_data_all->product_distribution->product_multimedia;
	    $this->process_product_multimedia($post_id, $media, $product_id);

        // Update post meta
        //debug_only($update_meta);
        foreach( $update_meta as $key => $value ) {
            update_field($key, $value, $post_id);
        }

        $update_time = false;
		if( $product_data_all->product_distribution->product_record ) {
			$update_time = (string)$product_data_all->product_distribution->product_record->product_update_date;
		}

        return $update_time;
    }

    // Setup common data shared by all post types
    public function common_data($post_id) {
        
        $update_meta = array();
        
        // Location / Address
        $update_meta = $this->update_address_basic($update_meta);

        // Map Latitude & Longitude
        $update_meta = $this->update_address_full($update_meta);
        
        // Contact Info
        $update_meta = $this->update_communication($update_meta);
        
        // Opening Time
        $update_meta = $this->update_opening_time($update_meta);

        // Category
        // Check in & out time
        $update_meta = $this->update_record($update_meta);

        // Price
        $update_meta = $this->update_entry_cost($update_meta);

        // Features, Facilities
        // Distance
        $update_meta = $this->update_attribute($update_meta);

        // Social Media
        $update_meta = $this->update_external_system($update_meta);
        
        return $update_meta;
    }

    public function check_post_exist($product_id, $post_type){
        $post = get_posts(array(
            'post_type' => $post_type,
            'showposts' => 1,
            'meta_query' => array(
                array(
                    'key'     => 'product_id',
                    'value'   => $product_id,
                    'compare' => '='
                )
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        if( $post ) {
            return $post[0]; // return post id
        }
        return false;
    }

    // Events specific
    public function specific_data_events( $post_id, $update_meta ) {

        $product_data = $this->product_data;
        $product_data_all = $this->product_data_all;

        $update_meta['edo_status'] = (string)$product_data->status;
        $update_meta['edo_start_date'] = date('Ymd', strtotime((string)$product_data->start_date));
        $update_meta['edo_end_date'] = date('Ymd', strtotime((string)$product_data->end_date));
        $update_meta['edo_frequency'] = (string)$product_data_all->product_distribution->product_record->attribute_id_frequency_description;

        $product_rates = $product_data_all->product_distribution->rates->row;
        if( $product_rates ){
            $costs = array();
            foreach( $product_rates as $rate ) {
                if( $rate ) {
                    $costs[] = array(
                        'edo_label' => (string)$rate->rates_type_description,
                        'edo_cost_txt' => price_range((int)$rate->price_from, (int)$rate->price_to),
                        //'other_info' => (string)$rate->rate_comment,
                    );
                }
            }
            $update_meta['edo_cost'] = $costs;
        }

        // Event Schedule
        if( $product_data_all->product_distribution->event_frequency->row ) {
            $product_event_time = $product_data_all->product_distribution->event_frequency;
            $schedule = array();
            foreach( $product_event_time->row as $event_frequency ) {
                $temp = array(
                    'start_date' => (string)$event_frequency->frequency_start_date,
                    'end_date' => (string)$event_frequency->frequency_end_date,
                    'start_time' => (string)$event_frequency->frequency_start_time,
                    'end_time' => (string)$event_frequency->frequency_end_time,
                );
                $schedule[] = $temp;
            }
            if( $schedule ) {
                $update_meta['event_schedule'] = $schedule;
            }
        }

        // Categories
        $categories = $product_data_all->product_distribution->product_vertical_classification->row;
        foreach( $categories as $category ) {
            $category_name = (string)$category->product_type_description;
            $this->set_post_term_with_fallback($post_id, $category_name, 'events_category');
        }
        
        return $update_meta;
    }

    // See and do Specific
    public function specific_data_see_and_do( $post_id, $update_meta ) {
        
        $product_data_all = $this->product_data_all;

        // Category Type
        $categories = $product_data_all->product_distribution->product_vertical_classification->row;
        foreach( $categories as $category ) {
            $category_name = (string)$category->product_type_description;
            $this->set_post_term_with_fallback($post_id, $category_name, 'see_and_do_category_type');
        }
        
        return $update_meta;
    }

    // Taste Spcific
    public function specific_data_taste( $post_id, $update_meta ) {
        
        $product_data_all = $this->product_data_all;

        // Category
        $categories = $product_data_all->product_distribution->product_vertical_classification->row;
        foreach( $categories as $category ) {
            $category_name = (string)$category->product_type_description;
            $this->set_post_term_with_fallback($post_id, $category_name, 'taste_category');
        }
        return $update_meta;
    }

    // Stay Specific
    public function specific_data_stay( $post_id, $update_meta ) {
        
        $product_data_all = $this->product_data_all;

        // Category
        $categories = $product_data_all->product_distribution->product_vertical_classification->row;
        foreach( $categories as $category ) {
            $category_name = (string)$category->product_type_description;
            $this->set_post_term_with_fallback($post_id, $category_name, 'stay_category');
        }
        return $update_meta;
    }

    // Address - Basic Data
    public function update_address_basic($update_meta) {

        $product_data = $this->product_data;

        // Location / Address
        $address = $product_data->addresses->address;

        $update_meta['status'] = (string)$product_data->status;
        
        $address_line = (string)$address->address_line;
        $city = (string)$address->city;
        $state = (string)$address->state;

        $postcode = (string)$address->postcode;

        $address_string = '';
        if( $address_line ) $address_string .= $address_line .',';
        if( $city ) $address_string .= ' '.  $city;
        if( $state ) $address_string .= ' '. $state;
        if( $postcode ) $address_string .= ' '. $postcode;
        $update_meta['location_full'] = $address_string;

        // Full location
        $update_meta['location'] = array(
            'address_line' => $address_line,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
        );

        return $update_meta;
    }

    // Address - All data
    public function update_address_full($update_meta) {
        
        $product_data_all = $this->product_data_all;
    
        $full_address = $product_data_all->product_distribution->product_address->row;

        // Map latitude & Longitude
        $update_meta['map_lat'] = (string)$full_address->geocode_gda_latitude;
        $update_meta['map_long'] = (string)$full_address->geocode_gda_longitude;

        return $update_meta;
    }

    // Product Information
    public function update_communication($update_meta) {

        $product_data_all = $this->product_data_all;

        $product_communication = $product_data_all->product_distribution->product_communication;
        foreach( $product_communication->row as $info ) {
            $type = (string)$info->attribute_id_communication_description;
            $value = (string)$info->communication_detail;
            switch( $type ) {
                case 'Email Enquiries':
                    $update_meta['info_email'] = $value;
                    break;

                case 'Primary Phone':
                    $phone = '';
                    $area_code = (string)$info->communication_area_code;
                    if( $area_code ) $phone .= $area_code .' ';
                    $phone .= $value;
                    $update_meta['info_phone'] = $phone;
                    break;

                case 'Secondary Phone':
                    // If primary phone is not set, set this as primary
                    if( !isset($update_meta['info_phone']) ) {
                        $phone = '';
                        $area_code = (string)$info->communication_area_code;
                        if( $area_code ) $phone .= $area_code .' ';
                        $phone .= $value;
                        $update_meta['info_phone'] = $phone;
                    }
                    break;

                case 'URL Enquiries':
                    $update_meta['info_website'] = $value;
                    break;

                case 'Booking URL':
                    $update_meta['booking_url'] = $value;
                    break;
            }
        }

        return $update_meta;
    }

    // Product Opening Time
    public function update_opening_time($update_meta) {

        $product_data_all = $this->product_data_all;

        if( $product_data_all->product_distribution->product_opening_time->seasonal_periods->row ) {
            $product_opening_time = $product_data_all->product_distribution->product_opening_time->seasonal_periods->row->open_status->row;
            $opening_time = $product_opening_time->opening_time;
            if( $opening_time ) {
                $update_meta['opening_time'] = (string)$opening_time;
            }
            $closing_time = $product_opening_time->closing_time;
            if( $closing_time ) {
                $update_meta['closing_time'] = (string)$closing_time;
            }
    
            // All opening hours
            $all_opening_hours = array();
            foreach( $product_opening_time as $opening_hours ) {
                $temp = array(
                    'day' => (string)$opening_hours->day_of_week,
                    'opening_time' => (string)$opening_hours->opening_time,
                    'closing_time' => (string)$opening_hours->closing_time,
                );
                if( (string)$opening_hours->is_closed == 'true' ) {
                    $temp['is_closed'] = true;
                }
                $all_opening_hours[] = $temp;
            }
            if( $all_opening_hours ) {
                $update_meta['all_opening_hours'] = $all_opening_hours;
            }
    
        }
        
        return $update_meta;
    }

    // Product Record
    public function update_record($update_meta) {

        $product_data_all = $this->product_data_all;

        $product_record = $product_data_all->product_distribution->product_record;

        // Product Category
        $update_meta['product_category'] = (string)$product_record->product_category_description;
        
        // Check in time
        if( $product_record->check_in_time ) {
            $check_in_time = (string)$product_record->check_in_time;
            $update_meta['check_in_time'] = $this->format_time($check_in_time);
        }
        // Check out time
        if( $product_record->check_out_time ) {
            $check_out_time = (string)$product_record->check_out_time;
            $update_meta['check_out_time'] = $this->format_time($check_out_time);
        }
        
        return $update_meta;
    }

    // Product Entry Cost
    public function update_entry_cost($update_meta){

        $product_data_all = $this->product_data_all;

        $product_cost = $product_data_all->product_distribution->product_entry_cost->row;

        $cost = $product_cost->entry_cost;
        if( $product_cost->entry_cost_from ) {
            $update_meta['price_from'] = (string)$product_cost->entry_cost_from;
        }
        if( $product_cost->entry_cost_to ) {
            $update_meta['price_to'] = (string)$product_cost->entry_cost_to;
        }

        return $update_meta;
    }

    // Product Attribute
    public function update_attribute($update_meta) {

        $product_data_all = $this->product_data_all;

        $product_attributes = $product_data_all->product_distribution->product_attribute;

        $save_product_attributes = array();

        foreach( $product_attributes->row as $data ) {
            
            switch( (string)$data->attribute_type_id ) {

                // Distance
                case "DIST UNIT":
                    $unit = strtolower((string)$data->attribute_id);
                    if( $unit == 'kms' ) $unit = 'km';
                    $distance = (string)$data->attribute_text . ' '. $unit;
                    $update_meta['distance'] = $save_product_attributes;
                break;

                // Other data
                // Features, Facilities
                default:
                    $save_product_attributes[] = array(
                        'label' => (string)$data->attribute_type_id_description,
                        'value' => (string)$data->attribute_id_description
                    );
            }
            
        }

        if( $save_product_attributes ) {
            $update_meta['product_attributes'] = $save_product_attributes;
        }

        return $update_meta;
    }

    // Product External System
    public function update_external_system($update_meta) {

        $product_data_all = $this->product_data_all;

        $product_external = $product_data_all->product_distribution->product_external_system;

        $save_product_external = array();

        foreach( $product_external->row as $data ) {
            $save_product_external[] = array(
                'label' => (string)$data->external_system_code,
                'value' => (string)$data->external_system_text
            );	
        }

        $update_meta['product_external'] = $save_product_external;

        return $update_meta;
    }

    // Product Service ONGOING DEVELOPMENT
    public function update_service($update_meta) {
        
        $product_data_all = $this->product_data_all;

        $product_service = $product_data_all->product_distribution->product_service;
		if( $product_service ) {
            debug_only(count($product_service->row));
            // Itinerary
			foreach( $product_service->row as $service ) {
				debug_only((string)$service->service_name);
				debug_only($service);
			}
        }
        
        return $update_meta;
    }

    // Update Product Ids
    public function update_product_ids($post_type){

        $args = $this->build_args($post_type);
        debug_only($args);
        $data = $this->run_query($args, 'xml');

        $number_of_results = (string)$data->number_of_results;
        debug_only($number_of_results);
        $products_count = count($data->products->product_record);
        debug_only($products_count);
        
        // Loop
        $products = $data->products->product_record;
        foreach( $products as $product_data ) {
            $product_name = wp_strip_all_tags( (string)$product_data->product_name );
            debug_only($product_name);
            $post_exists =  get_page_by_title($product_name, OBJECT, $post_type);
            if( $post_exists ) {
                // Post Exists
                $product_id = (string)$product_data->product_id;
                $post_id = $post_exists->ID;
                debug_only('Add id = '. $product_id);
                update_field('product_id', $product_id, $post_id);
            } 
        }
    }

    // Process Images and Videos
    public function process_product_multimedia($post_id, $data, $product_id){
        if( !$data ) return;
    
        $processed_ids = array();
        $unique_images = array();
        $gallery_videos = array();
    
        // Loop through the images, get unique images
        foreach( $data->row as $media ) {
    
            $media_id = (string)$media->multimedia_id;
    
            // Skip already processed image ids, avoid duplicate
            if( in_array($media_id, $processed_ids) ) continue;
    
            // Process Images
            // Only get images with 800px width
            if( (int)$media->width == 800 ) {
                $unique_images[] = array(
                    'url' => (string)$media->server_path,
                    'description' => (string)$media->alt_text,
                );
                array_push($processed_ids, $media_id);
            }
    
            // Process Videos
            if( (string)$media->attribute_id_multimedia_content == 'VIDEO' ) {
                //debug_only($media);
                $gallery_videos[] = array(
                    'link' => (string)$media->server_path,
                    'thumbnail' => (string)$media->video_thumbnail_path,
                );
            }
    
        } // loop media
        
    
        if( $gallery_videos ) {
            // Save video gallery to post meta
            update_field( 'video_gallery', $gallery_videos , $post_id );
        }
    
        $gallery_images = array(); // container for uploaded image ids
    
        //$time_start = microtime(true);
        foreach( $unique_images as $image ) {
    
            $img_id = $this->upload_and_attach_image_to_post( $post_id, $image['url'], $image['description'] );
            
            // Store queried image ids
            if( $img_id ) {
                //debug_only('Store queried image ids = '. $img_id);
                array_push($gallery_images, $img_id);
            }
            //debug_only('<b>Per item:</b> '. ( $time_start - microtime(true) ));
        }
        
        if( $gallery_images )  {
            if( $gallery_images[0] ) {
                // Set first image as featured
                set_post_thumbnail( $post_id, $gallery_images[0] );
                // Set as header bg
                update_field('header_bg', $gallery_images[0], $post_id);
            }
            // Save image gallery to post meta
            update_field( 'info_gallery', $gallery_images , $post_id );
        }
        
    }
    
    // Query all product data
    public function get_all_product_data($product_id, $return_type = 'xml') {
        
        $args = array(
            'key' => $this->api_key,
            'productId' => $product_id,
            'out' => $return_type,
        );

        $query_url = 'http://atlas.atdw-online.com.au/api/atlas/product?'. http_build_query($args);

	    return $this->curl($query_url, $return_type);
    }

    public function format_time($time, $format = 'g:iA'){
        // input = 1400, output = 14:00
        $temp = substr_replace($time, ':', 2, 0);
		// input = 14:00 | output = 2:00pm
        return strtolower(@date($format, strtotime($temp)));
    }

    // Set post term to post id, create term if it does not exist
    public function set_post_term_with_fallback($post_id, $value, $taxonomy, $create_new = true ){
        if( $value == '' ) return;
        // Search term
        $term = term_exists( $value, $taxonomy);
        if( $term ) {
            // Term exists
            wp_set_post_terms( $post_id, $term['term_id'], $taxonomy, true );
        } else {
            // Term does not exist, create new
            if( $create_new ) {
                // Create new term
                $new_term = wp_insert_term($value, $taxonomy);
                if( $new_term ) {
                    wp_set_post_terms( $post_id, $new_term, $taxonomy, true );
                }
            }
        }
    }

    // Upload and attach image to post
    function upload_and_attach_image_to_post( $post_id, $image_url, $image_description = '' ){
        // Check if image file exists
        if (@getimagesize($image_url)) {
            $set_image = false;
            $image_url = trim($image_url);
            if (strpos($image_url, $_SERVER['SERVER_NAME']) !== false) {
                // Internal source, search db for image url
                $check_image = $this->get_image_id_by_url($image_url, 'full');
            } else {
                // External source, search db for file name
                $image_name = wp_basename($image_url);
                // remove query strings:
                $remove_query_strings = explode('?', $image_name);
                $image_name = $remove_query_strings[0];
    
                //debug_only('image name = '. $image_name);
                $check_image = $this->get_image_id_by_url($image_name, 'filename');
            }
    
            if( $check_image ) {
                // Image already exists, get id
                $set_image = $check_image;
            } else {
                // Image does not exist in the site, download and upload the new image to the site
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $set_image = media_sideload_image($image_url, $post_id, $image_description, 'id');
            }
    
            return $set_image;
        }
    }

    function get_image_id_by_url($image_url, $type = 'full') {
		global $wpdb;
		if( $type === 'full' ) {
			// Search url
			$attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url ));
		} else {
			// Search name using LIKE
			$attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid LIKE '%s';", '%' . $wpdb->esc_like($image_url) . '%' ));
		}
		if( $attachment )
			return $attachment[0];
	}

    public function xml2array( $xmlObject, $out = array () )
    {
        foreach ( (array) $xmlObject as $index => $node )
            $out[$index] = ( is_object ( $node ) ) ? $this->xml2array ( $node ) : $node;
        return $out;
    }
}