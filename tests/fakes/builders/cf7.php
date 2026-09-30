<?php
// Contact Form 7, as far as the AguMail adapter touches it: a contact form
// with its properties and form tags, a form tag with CF7's own option
// matching, and the submission of the current request. Shapes follow Contact
// Form 7 6.1 (includes/contact-form.php, form-tag.php, submission.php).
// Loaded only by the scenarios that need it.

if (!class_exists('WPCF7_FormTag')) {
    class WPCF7_FormTag {
        public $type = '';
        public $basetype = '';
        public $raw_name = '';
        public $name = '';
        public $options = array();
        public $raw_values = array();
        public $values = array();
        public $labels = array();
        public $content = '';
        public function __construct(array $tag = array()) {
            foreach ($tag as $key => $value) {
                $this->$key = $value;
            }
        }
        /** As CF7: the option itself, or the option with a value after a colon. */
        public function has_option($option_name) {
            $pattern = sprintf('/^%s(:.+)?$/i', preg_quote($option_name, '/'));
            return (bool) preg_grep($pattern, $this->options);
        }
    }

    class WPCF7_ContactForm {
        public $id;
        public $title;
        public $properties = array();
        public $tags = array();
        public $demo = false;
        public function __construct($id, $title, array $tags, array $properties = array()) {
            $this->id = (int) $id;
            $this->title = $title;
            $this->tags = $tags;
            $this->properties = apply_filters('wpcf7_pre_construct_contact_form_properties', array(
                'form' => '', 'mail' => array(), 'mail_2' => array(), 'messages' => array(), 'additional_settings' => '',
            ), $this);
            foreach ($properties as $name => $value) {
                $this->properties[$name] = $value;
            }
        }
        public function id() { return $this->id; }
        public function title() { return $this->title; }
        public function prop($name) { return isset($this->properties[$name]) ? $this->properties[$name] : null; }
        public function get_properties() { return $this->properties; }
        public function set_properties(array $properties) {
            foreach ($properties as $name => $value) {
                if (array_key_exists($name, $this->properties)) {
                    $this->properties[$name] = $value;
                }
            }
        }
        /** Every tag, or those matching a condition such as ['type' => 'acceptance'] or ['basetype' => 'email']. */
        public function scan_form_tags($cond = null) {
            if (!is_array($cond)) {
                return $this->tags;
            }
            return array_values(array_filter($this->tags, function ($tag) use ($cond) {
                foreach ($cond as $key => $value) {
                    if (!in_array($tag->$key, (array) $value, true)) {
                        return false;
                    }
                }
                return true;
            }));
        }
        public function in_demo_mode() { return $this->demo; }
    }

    class WPCF7_Submission {
        public static $instance = null;
        public $posted_data = array();
        public $meta = array();
        public $status = 'init';
        public static function get_instance($contact_form = null, $args = '') {
            return self::$instance;
        }
        public function get_posted_data($name = '') {
            if ($name !== '') {
                return isset($this->posted_data[$name]) ? $this->posted_data[$name] : null;
            }
            return $this->posted_data;
        }
        public function get_posted_string($name) {
            $data = $this->get_posted_data($name);
            $data = is_array($data) ? array_values($data) : array($data);
            return isset($data[0]) ? trim((string) $data[0]) : '';
        }
        public function get_meta($name) { return isset($this->meta[$name]) ? $this->meta[$name] : null; }
        public function get_status() { return $this->status; }
    }
}

/** A form tag as CF7 scans it from "[type name options...] content". */
function agm_cf7_tag($type, $name, array $options = array(), $content = '', array $values = array()) {
    return new WPCF7_FormTag(array(
        'type'       => $type,
        'basetype'   => rtrim($type, '*'),
        'raw_name'   => $name,
        'name'       => $name,
        'options'    => $options,
        'raw_values' => $values,
        'values'     => $values,
        'labels'     => $values,
        'content'    => $content,
    ));
}

/** A contact form with its tags and, optionally, saved AguMail settings. */
function agm_cf7_form($id, $title, array $tags, array $agumail = null) {
    $properties = array();
    if ($agumail !== null) {
        $properties['agumail_signup'] = $agumail;
    }
    return new WPCF7_ContactForm($id, $title, $tags, $properties);
}

/**
 * Submits $form as CF7's REST feedback endpoint does: the submission of this
 * request, then wpcf7_submit, then the feedback response filter. Returns the
 * response the browser receives.
 */
function agm_cf7_submit(WPCF7_ContactForm $form, array $posted, $status = 'mail_sent', array $meta = array()) {
    $submission = new WPCF7_Submission();
    $submission->posted_data = $posted;
    $submission->status = $status;
    $submission->meta = array_merge(array('url' => 'https://shop.test/contact/', 'remote_ip' => '203.0.113.9'), $meta);
    WPCF7_Submission::$instance = $submission;
    $result = array('contact_form_id' => $form->id(), 'status' => $status, 'message' => 'Thank you.', 'posted_data_hash' => $status === 'mail_sent' || $status === 'mail_failed' ? md5(serialize($posted)) : '');
    if ($form->in_demo_mode()) {
        $result['demo_mode'] = true;
    }
    do_action('wpcf7_submit', $form, $result);
    $response = array_merge($result, array('into' => '#wpcf7-f' . $form->id() . '-o1', 'invalid_fields' => array()));
    return apply_filters('wpcf7_feedback_response', $response, $result);
}
