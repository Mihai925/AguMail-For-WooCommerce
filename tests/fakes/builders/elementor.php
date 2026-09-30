<?php
// Elementor Pro's form classes, as far as the AguMail action touches them:
// the action base classes (Integration_Base keeps its real
// register_fields_map_control()), the submitted record, the AJAX handler that
// answers the visitor, the actions registrar and a form widget that records
// the controls added to it. Shapes follow Elementor Pro 4.0.3
// (modules/forms/classes). Loaded only by the scenarios that need it.
//
// AGM_ELEMENTOR_NO_ALERT=1 plays an Elementor older than 3.19, whose
// Controls_Manager has no ALERT control yet.

namespace Elementor {
    if (!class_exists(__NAMESPACE__ . '\Controls_Manager') && getenv('AGM_ELEMENTOR_NO_ALERT')) {
        class Controls_Manager {
            const TEXT = 'text';
            const SWITCHER = 'switcher';
            const RAW_HTML = 'raw_html';
            const HIDDEN = 'hidden';
            const SELECT = 'select';
        }
    } elseif (!class_exists(__NAMESPACE__ . '\Controls_Manager')) {
        class Controls_Manager {
            const TEXT = 'text';
            const SWITCHER = 'switcher';
            const RAW_HTML = 'raw_html';
            const ALERT = 'alert';
            const HIDDEN = 'hidden';
            const SELECT = 'select';
        }
    }
}

namespace ElementorPro\Modules\Forms\Classes {
    if (!class_exists(__NAMESPACE__ . '\Action_Base')) {
        abstract class Action_Base {
            abstract public function get_name();
            abstract public function get_label();
            public function get_id() {
                return $this->get_name();
            }
            abstract public function run($record, $ajax_handler);
            abstract public function register_settings_section($form);
            abstract public function on_export($element);
        }

        abstract class Integration_Base extends Action_Base {
            public function handle_panel_request(array $data) {}
            protected function get_fields_map_control_options() {
                return array();
            }
            final protected function register_fields_map_control($form) {
                $form->add_control($this->get_name() . '_fields_map', array_merge(array(
                    'label'     => 'Field Mapping',
                    'type'      => 'fields_map',
                    'separator' => 'before',
                    'fields'    => array(array('name' => 'remote_id', 'type' => 'hidden'), array('name' => 'local_id', 'type' => 'select')),
                ), $this->get_fields_map_control_options()));
            }
        }

        /** The submitted form: fields by custom id ({id, type, title, value, raw_value, required}) and the widget's settings. */
        class Form_Record {
            protected $fields;
            protected $form_settings;
            protected $form_type = 'form';
            public function __construct(array $fields, array $form_settings) {
                $this->fields = $fields;
                $this->form_settings = $form_settings;
            }
            public function get($property) {
                return isset($this->{$property}) ? $this->{$property} : null;
            }
            public function get_form_settings($setting) {
                return isset($this->form_settings[$setting]) ? $this->form_settings[$setting] : null;
            }
        }

        /** What answers the visitor. Anything an action adds to errors or messages fails their submission. */
        class Ajax_Handler {
            public $is_success = true;
            public $messages = array('success' => array(), 'error' => array(), 'admin_error' => array());
            public $data = array();
            public $errors = array();
            public function add_response_data($key, $data) {
                $this->data[$key] = $data;
                return $this;
            }
            public function add_error($field, $message = '') {
                $this->errors[$field] = $message;
                $this->is_success = false;
                return $this;
            }
            public function add_error_message($message) {
                $this->messages['error'][] = $message;
                $this->is_success = false;
                return $this;
            }
            public function add_admin_error_message($message) {
                $this->messages['admin_error'][] = $message;
                $this->is_success = false;
                return $this;
            }
            public function add_success_message($message) {
                $this->messages['success'][] = $message;
                return $this;
            }
        }
    }
}

namespace {
    /** Elementor Pro's Form_Actions_Registrar: register() keeps the first action of each id. */
    class Agm_Elementor_Registrar {
        public $items = array();
        public function register($instance, $id = null) {
            $id = $id ? $id : $instance->get_id();
            if (isset($this->items[$id])) {
                return false;
            }
            $this->items[$id] = $instance;
            return true;
        }
        public function get($id = null) {
            return $id === null ? $this->items : (isset($this->items[$id]) ? $this->items[$id] : null);
        }
    }

    /** The form widget while it registers its controls: sections and controls in order. */
    class Agm_Elementor_Widget {
        public $sections = array();
        public $controls = array();
        public $open = null;
        public function start_controls_section($id, array $args = array()) {
            $this->sections[$id] = $args;
            $this->open = $id;
        }
        public function add_control($id, array $args = array()) {
            $args['section'] = $this->open;
            $this->controls[$id] = $args;
            return true;
        }
        public function end_controls_section() {
            $this->open = null;
        }
    }

    /** Fires Elementor Pro's registration hook and returns the AguMail action it registered, or null. */
    function agm_elementor_action() {
        $registrar = new Agm_Elementor_Registrar();
        do_action('elementor_pro/forms/actions/register', $registrar);
        return $registrar->get('agumail');
    }

    /**
     * A submitted form as Elementor Pro hands it to actions. $fields: a list of
     * [custom_id, type, label, value, extra form_fields settings]; an extra
     * 'required' => 'true' makes the field required. $settings are
     * the widget's settings for display (defaults included, as
     * get_settings_for_display() returns them).
     */
    function agm_elementor_record(array $fields, array $settings = array()) {
        $record_fields = array();
        $form_fields = array();
        foreach ($fields as $field) {
            list($id, $type, $label, $value) = $field;
            $extra = isset($field[4]) ? $field[4] : array();
            $record_fields[$id] = array('id' => $id, 'type' => $type, 'title' => $label, 'value' => $value, 'raw_value' => $value, 'required' => !empty($extra['required']));
            $form_fields[] = array_merge(array('custom_id' => $id, 'field_type' => $type, 'field_label' => $label), $extra);
        }
        $settings = array_merge(array(
            'form_fields'           => $form_fields,
            'form_name'             => 'Welcome popup',
            'id'                    => 'abc123',
            'form_post_id'          => 12,
            'edit_post_id'          => 12,
            'submit_actions'        => array('agumail'),
            'agumail_tags'          => '',
            'agumail_double_opt_in' => 'yes',
            'agumail_fields_map'    => array(),
        ), $settings);
        return new \ElementorPro\Modules\Forms\Classes\Form_Record($record_fields, $settings);
    }

    /** Elementor Pro's AJAX handler, for one submission. */
    function agm_elementor_handler() {
        return new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
    }

    /** A fields map as the editor saves it: remote id => the form field's custom id. */
    function agm_elementor_map(array $map) {
        $items = array();
        foreach ($map as $remote => $local) {
            $items[] = array('_id' => substr(md5($remote), 0, 7), 'remote_id' => $remote, 'local_id' => $local);
        }
        return $items;
    }
}
