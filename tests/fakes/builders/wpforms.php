<?php
// WPForms, as far as the AguMail adapter touches it: the builder's
// wpforms_panel_field() (each call is recorded, and prints a small stand-in),
// the Settings panel the content hook receives, and a submission's fields and
// form data as wpforms_process_complete passes them. Shapes follow WPForms
// Lite 2.0 and Pro 1.9 (includes/admin/builder/functions.php,
// includes/class-process.php, includes/fields). Loaded only by the scenarios
// that need it.

$GLOBALS['agm']['wpforms_fields'] = array();

if (!function_exists('wpforms_panel_field')) {
    function wpforms_panel_field($option, $panel, $field, $form_data, $label, $args = array(), $do_echo = true) {
        $value = isset($args['value']) ? $args['value'] : (isset($form_data[$panel][$field]) ? $form_data[$panel][$field] : (isset($args['default']) ? $args['default'] : ''));
        $GLOBALS['agm']['wpforms_fields'][$field] = array('option' => $option, 'panel' => $panel, 'label' => $label, 'args' => $args, 'value' => $value);
        $html = '<div class="wpforms-panel-field" data-option="' . esc_attr($option) . '" data-field="' . esc_attr($field) . '">'
            . '<label>' . $label . '</label><span class="value">' . esc_html(is_scalar($value) ? (string) $value : '') . '</span>'
            . (isset($args['after']) ? $args['after'] : '') . '</div>';
        if ($do_echo) {
            echo $html;
            return null;
        }
        return $html;
    }
}

/** The builder's Settings panel, as wpforms_form_settings_panel_content passes it. */
class Agm_WPForms_Settings_Panel {
    public $form_data;
    public function __construct(array $form_data) {
        $this->form_data = $form_data;
    }
}

/** A form's data: its id, settings (form_title and the AguMail ones) and fields by id. */
function agm_wpforms_form($id, $title, array $fields, array $settings = array()) {
    $by_id = array();
    foreach ($fields as $field) {
        $by_id[$field['id']] = $field;
    }
    return array('id' => (string) $id, 'fields' => $by_id, 'settings' => array_merge(array('form_title' => $title), $settings));
}

/** A submitted field as WPForms formats it: {name, value, id, type} plus a Name field's first, middle and last. */
function agm_wpforms_field($id, $type, $label, $value, array $extra = array()) {
    return array_merge(array('name' => $label, 'value' => $value, 'id' => $id, 'type' => $type), $extra);
}

/**
 * A successful submission: wpforms_process_complete, then, for an AJAX form,
 * the success response filter. Returns the response the browser receives.
 */
function agm_wpforms_submit(array $form_data, array $fields, array $post = array()) {
    $_POST = array_merge($_POST, array('page_url' => 'https://shop.test/newsletter/'), $post);
    $by_id = array();
    foreach ($fields as $field) {
        $by_id[$field['id']] = $field;
    }
    do_action('wpforms_process_complete', $by_id, array('fields' => array()), $form_data, 101);
    return apply_filters('wpforms_ajax_submit_success_response', array('confirmation' => '<p>Thanks!</p>'), (int) $form_data['id'], $form_data);
}
