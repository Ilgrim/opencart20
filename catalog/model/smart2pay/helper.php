<?php

/**
 * Class ModelSmart2payHelper
 *
 * This is actually a helper class, not a model
 * 
 * @property DB $db
 * @property Config $config
 * @property Loader $load
 * @property Url $url
 * @property Log $log
 * @property Request $request
 * @property Response $response
 * @property Session $session
 * @property Language $language
 * @property Document $document
 * @property Customer $customer
 * @property Currency $currency
 * @property Cart $cart
 * @property Event $event
 * @property User $user
 * @property ModelSettingSetting $model_setting_setting
 * @property ModelLocalisationOrderStatus $model_localisation_order_status
 */
class ModelSmart2payHelper extends Model
{
    const MODULE_VERSION = '1.0.5';

    const ENV_DEMO = 1, ENV_TEST = 2, ENV_LIVE = 3;
    const S2P_STATUS_OPEN = 1, S2P_STATUS_SUCCESS = 2, S2P_STATUS_CANCELLED = 3, S2P_STATUS_FAILED = 4, S2P_STATUS_EXPIRED = 5, S2P_STATUS_PROCESSING = 7;
    const PAYMENT_METHOD_BT = 1, PAYMENT_METHOD_SIBS = 20;
    const CONFIRM_ORDER_PAID = 0, CONFIRM_ORDER_FINAL_STATUS = 1, CONFIRM_ORDER_REDIRECT = 2, CONFIRM_ORDER_INITIATE = 3;

    static function valid_environment( $env )
    {
        $env = intval( $env );
        if( !in_array( $env, array( self::ENV_DEMO, self::ENV_TEST, self::ENV_LIVE ) ) )
            return false;

        return true;
    }

    /**
     * Get all method settings in a single array
     *
     * @return array
     */
    public function getall_method_settings( $params = false )
    {
        $this->load->model( 'smart2pay/helper' );

        if( empty( $params ) or !is_array( $params ) )
            $params = array();

        if( empty( $params['include_countries'] ) )
            $params['include_countries'] = false;

        if( empty( $params['include_countries'] ) )
            $sql_str = 'SELECT * FROM ' . DB_PREFIX . 'smart2pay_method ORDER BY display_name';
        else
            $sql_str = 'SELECT '.DB_PREFIX.'smart2pay_method.*, '.
                       ' '.DB_PREFIX.'smart2pay_country.country_id AS country_id, '.DB_PREFIX.'smart2pay_country.code AS country_code, '.DB_PREFIX.'smart2pay_country.name AS country_name '.
                       ' FROM '.DB_PREFIX.'smart2pay_method '.
                       ' LEFT JOIN '.DB_PREFIX.'smart2pay_country_method ON '.DB_PREFIX.'smart2pay_method.method_id = '.DB_PREFIX.'smart2pay_country_method.method_id '.
                       ' LEFT JOIN '.DB_PREFIX.'smart2pay_country ON '.DB_PREFIX.'smart2pay_country.country_id = '.DB_PREFIX.'smart2pay_country_method.country_id '.
                       ' ORDER BY '.DB_PREFIX.'smart2pay_method.display_name ASC, '.DB_PREFIX.'smart2pay_country.name ASC';

        if( !($query = $this->db->query( $sql_str ))
            or !is_object( $query ) or empty( $query->rows ) )
            return array();

        $methods = array();
        foreach( $query->rows as $method_arr )
        {
            if( empty( $method_arr['provider_value'] ) )
                continue;

            if( empty( $methods[$method_arr['provider_value']] ) )
            {
                $methods[$method_arr['provider_value']]['db_details'] = $method_arr;
                $methods[$method_arr['provider_value']]['settings'] = $this->model_smart2pay_helper->get_module_settings( $method['provider_value'] );
                $methods[$method_arr['provider_value']]['countries'] = array();
            }

            if( !empty( $params['include_countries'] )
                and !empty( $method_arr['country_id'] ) )
            {
                $methods[$method_arr['provider_value']]['countries'][$method_arr['country_id']] = array(
                    'code' => $method_arr['country_code'],
                    'name' => $method_arr['country_name'],
                );
            }
        }

        return $methods;
    }

    public function get_countries_for_method( $method_id )
    {
        $method_id = intval( $method_id );
        if( empty( $method_id ) )
            return array();

        if( !($query = $this->db->query( 'SELECT '.DB_PREFIX.'smart2pay_country.* FROM '.DB_PREFIX.'smart2pay_country_method '.
                                         ' LEFT JOIN '.DB_PREFIX.'smart2pay_country ON '.DB_PREFIX.'smart2pay_country.country_id = '.DB_PREFIX.'smart2pay_country_method.country_id '.
                                         ' WHERE '.DB_PREFIX.'smart2pay_country_method.method_id = \''.$method_id.'\' '.
                                         ' ORDER BY '.DB_PREFIX.'smart2pay_country.name' ))
            or !is_object( $query ) or empty( $query->rows ) )
            return array();

        $return_arr = array();
        foreach( $query->rows as $country_arr )
            $return_arr[$country_arr['country_id']] = $country_arr;

        return $return_arr;

    }

    public function get_module_settings( $module_name = '' )
    {
        $this->load->model( 'setting/setting' );

        return $this->model_setting_setting->getSetting( 'smart2pay' . ($module_name!=''?'_':'').$module_name );
    }

    public function save_module_settings( $settings_arr, $module_name = '' )
    {
        // If accessing in front-end we don't have localisation/order_status model
        if( !defined( 'DIR_CATALOG' ) )
            return false;

        $this->load->model( 'setting/setting' );

        if( !($saved_settings = $this->get_module_settings( $module_name )) )
            $saved_settings = array();

        $new_settings = array_merge( $saved_settings, $settings_arr );

        $this->model_setting_setting->editSetting( 'smart2pay' . ($module_name!=''?'_':'').$module_name, $new_settings );

        return true;
    }

    public function save_methods_settings( $methods_settings_arr )
    {
        // If accessing in front-end we don't have localisation/order_status model
        if( !defined( 'DIR_CATALOG' )
         or empty( $methods_settings_arr ) or !is_array( $methods_settings_arr ) )
            return false;

        foreach( $methods_settings_arr as $method_name => $method_settings )
        {
            if( !$this->save_module_settings( $method_settings ) )
                $saved_settings = array();
        }

        return true;
    }

    /**
     * Returns default keys for a field array that is to be displayed in settings form
     * @return array
     */
    protected function default_field_values()
    {
        return array(
            'label'   => '',
            'type'    => '',
            'options' => array(),
            'value' => '',
            'required' => false,
            'multiple' => false,
            'extra_css' => '',
        );
    }

    /**
     * @param array $fields_arr Array of settings fields to be completed with all keys from default_field_values() method
     *
     * @return array|false Validated fields array
     */
    public function validate_settings_fields( $fields_arr, $module_name = '' )
    {
        if( empty( $fields_arr ) or !is_array( $fields_arr ) )
            return false;

        $key_prefix = 'smart2pay'.($module_name!=''?'_':'').$module_name.'_';
        $key_prefix_len = strlen( $key_prefix );

        $default_field_values = $this->default_field_values();
        $new_fields_arr = array();
        foreach( $fields_arr as $key => $field_arr )
        {
            if( empty( $field_arr ) or !is_array( $field_arr ) )
                continue;

            foreach( $default_field_values as $prop_key => $prop_value )
            {
                if( !array_key_exists( $prop_key, $field_arr ) )
                    $field_arr[$prop_key] = $prop_value;
            }

            if( substr( $key, 0, $key_prefix_len ) != $key_prefix )
                $key = $key_prefix.$key;

            $new_fields_arr[$key] = $field_arr;
        }

        return $new_fields_arr;
    }


    /**
     * Get module settings
     *
     * @return array
     */
    public function get_main_module_fields()
    {
        // If accessing in front-end we don't have localisation/order_status model
        if( !defined( 'DIR_CATALOG' ) )
            return array();

        if( !isset( $this->request->server['HTTPS'] )
         or $this->request->server['HTTPS'] != 'on' )
            $server_base = HTTP_CATALOG;
        else
            $server_base = HTTPS_CATALOG;

        $this->load->model( 'smart2pay/helper' );
        $this->load->model( 'localisation/order_status' );

        $moduleSettings = array(
            'smart2pay_status' =>
                array(
                    'label'   => 'Enabled',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
            'smart2pay_env' =>
                array(
                    'label'     => 'Environment',
                    'type'      => 'select',
                    'options'   =>
                        array(
                            self::ENV_DEMO => 'Demo',
                            self::ENV_TEST => 'Test',
                            self::ENV_LIVE => 'Live'
                        ),
                    'value' => 0
                ),
            'smart2pay_post_url_live' =>
                array(
                    'label' => 'Post URL Live',
                    'type'  => 'text',
                    'value' => 'https://api.smart2pay.com'
                ),
            'smart2pay_post_url_test' =>
                array(
                    'label' => 'Post URL Test',
                    'type'  => 'text',
                    'value' => 'https://apitest.smart2pay.com'
                ),
            'smart2pay_signature_live' =>
                array(
                    'label' => 'Signature Live',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_signature_test' =>
                array(
                    'label' => 'Signature Test',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_mid_live' =>
                array(
                    'label' => 'MID Live',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_mid_test' =>
                array(
                    'label' => 'MID Test',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_site_id' =>
                array(
                    'label' => 'Site ID',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_skin_id' =>
                array(
                    'label' => 'Skin ID',
                    'type'  => 'text',
                    'value' => '',
                ),
            'smart2pay_return_url' =>
                array(
                    'label' => 'Return URL',
                    'type'  => 'text',
                    'value' => $server_base . 'index.php?route=payment/smart2pay/feedback'
                ),
            'smart2pay_send_order_number_as_product_description' =>
                array(
                    'label'   => 'Send order number as product description',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
            'smart2pay_custom_product_description' =>
                array(
                    'label' => 'Custom product description',
                    'type'  => 'textarea',
                    'value' => null
                ),
            'smart2pay_notify_customer_by_email' =>
                array(
                    'label'   => 'Notify customer by email',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
            //'smart2pay_create_invoice_on_success' =>
            //    array(
            //        'label'   => 'Create invoice on success',
            //        'type'    => 'select',
            //        'options' =>
            //            array(
            //                0 => 'No',
            //                1 => 'Yes'
            //            ),
            //        'value' => 0
            //    ),
            //'smart2pay_automate_shipping' =>
            //    array(
            //        'label'   => 'Automate shipping',
            //        'type'    => 'select',
            //        'options' =>
            //            array(
            //                0 => 'No',
            //                1 => 'Yes'
            //            ),
            //        'value' => 0
            //    ),
            'smart2pay_order_confirm' =>
                array(
                    'label'   => 'Confirm order',
                    'type'    => 'select',
                    'options' =>
                        array(
                            self::CONFIRM_ORDER_PAID => 'Only when paid',
                            self::CONFIRM_ORDER_FINAL_STATUS => 'On final status',
                            self::CONFIRM_ORDER_REDIRECT => 'On redirect',
                            self::CONFIRM_ORDER_INITIATE => 'On initiate',
                        ),
                    'value' => self::CONFIRM_ORDER_PAID,
                ),
            'smart2pay_order_status_new' =>
                array(
                    'label'   => 'Order status when NEW',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'Status 1',
                            1 => 'Status 2'
                        ),
                    'value' => 1
                ),
            'smart2pay_order_status_success' =>
                array(
                    'label'   => 'Order status when SUCCESS',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'Status 1',
                            1 => 'Status 2'
                        ),
                    'value' => 1
                ),
            'smart2pay_order_status_canceled' =>
                array(
                    'label'   => 'Order status when CANCEL',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'Status 1',
                            1 => 'Status 2'
                        ),
                    'value' => 1
                ),
            'smart2pay_order_status_failed' =>
                array(
                    'label'   => 'Order status when FAIL',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'Status 1',
                            1 => 'Status 2'
                        ),
                    'value' => 1
                ),
            'smart2pay_order_status_expired' =>
                array(
                    'label'   => 'Order status on EXPIRED',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'Status 1',
                            1 => 'Status 2'
                        ),
                    'value' => 1
                ),
            'smart2pay_skip_payment_page' =>
                array(
                    'label'   => 'Skip Payment Page',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
            'smart2pay_redirect_in_iframe' =>
                array(
                    'label'   => 'Redirect In IFrame',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
           'smart2pay_debug_form' =>
                array(
                    'label'   => '[Debug Form]',
                    'type'    => 'select',
                    'options' =>
                        array(
                            0 => 'No',
                            1 => 'Yes'
                        ),
                    'value' => 0
                ),
            'smart2pay_sort_order' =>
                array(
                    'label'   => 'Sort Order',
                    'type'    => 'text',
                    'value'   => ''
                ),
        );

        /*
         * Get order statuses
         */
        $orderStatuses = $this->model_localisation_order_status->getOrderStatuses();
        $orderStatusesIndexed = array();
        foreach( $orderStatuses as $status )
            $orderStatusesIndexed[$status['order_status_id']] = $status['name'];

        $moduleSettings['smart2pay_order_status_new']['options']     = $orderStatusesIndexed;
        $moduleSettings['smart2pay_order_status_success']['options'] = $orderStatusesIndexed;
        $moduleSettings['smart2pay_order_status_canceled']['options']  = $orderStatusesIndexed;
        $moduleSettings['smart2pay_order_status_failed']['options']    = $orderStatusesIndexed;
        $moduleSettings['smart2pay_order_status_expired']['options'] = $orderStatusesIndexed;

        if( !($moduleSettings = $this->validate_settings_fields( $moduleSettings, '' )) )
            return array();

        return $moduleSettings;
    }

    /**
     * Get logs
     *
     * @return array
     */
    public function getLogs()
    {
        $logs = array();

        $query = $this->db->query( 'SELECT * FROM ' . DB_PREFIX . 'smart2pay_log ORDER BY log_created DESC' );

        foreach( $query->rows as $method )
            $logs[] = $method;

        return $logs;
    }
}