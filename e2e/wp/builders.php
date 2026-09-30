<?php
/**
 * One signup form per form plugin, each on its own page, with AguMail switched on. Idempotent: forms and
 * pages are found again by their slug or title and brought back to this state. Run by
 * `./wp-setup.sh builders on` (the plugins must be active):
 *   wp eval-file /e2e/wp/builders.php
 *
 *   Elementor Pro   page agumail-e2e-elementor: a form widget "AguMail popup" with Name, Email and an
 *                   optional acceptance box, Actions After Submit = AguMail (tags "popup, e2e", double
 *                   opt-in on, nothing mapped: every field is detected)
 *   Contact Form 7  form "AguMail e2e contact" on page agumail-e2e-cf7: name, email and an optional box
 *                   marked consent_for:agumail; AguMail on, tags "contact, e2e", double opt-in off
 *   WPForms Lite    form "AguMail e2e newsletter" (AJAX) on page agumail-e2e-wpforms: a first and last
 *                   Name field, Email and a Newsletter checkbox mapped as the consent; AguMail on,
 *                   tags "news, e2e", double opt-in on
 *
 * Writes /e2e/state/builders.json for the specs.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

// Elementor saves a document only for a user who may edit it.
wp_set_current_user(1);

function agumail_e2e_builder_page($slug, $title, $content) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    $data = array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content);
    if ($page) {
        $data['ID'] = $page->ID;
        wp_update_post(wp_slash($data));
        return (int) $page->ID;
    }
    return (int) wp_insert_post(wp_slash($data));
}

function agumail_e2e_post_by_title($type, $title) {
    $posts = get_posts(array('post_type' => $type, 'post_status' => 'any', 'title' => $title, 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC'));
    return $posts ? $posts[0] : null;
}

$builders = array();

// ── Elementor Pro ────────────────────────────────────────────────────────────

if (!defined('ELEMENTOR_PRO_VERSION') || !class_exists('\Elementor\Plugin')) {
    WP_CLI::error('Elementor and Elementor Pro must be active.');
}
$elementor_page = agumail_e2e_builder_page('agumail-e2e-elementor', 'Signup (Elementor)', '');
$form_element = 'a9e2e01';
$elements = array(array(
    'id'       => 'a9e2e00',
    'elType'   => 'container',
    'settings' => array(),
    'isInner'  => false,
    'elements' => array(array(
        'id'         => $form_element,
        'elType'     => 'widget',
        'widgetType' => 'form',
        'settings'   => array(
            'form_name'             => 'AguMail popup',
            'form_fields'           => array(
                array('_id' => 'f9e2e01', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name', 'placeholder' => 'Name', 'width' => '100'),
                array('_id' => 'f9e2e02', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Email', 'placeholder' => 'Email', 'required' => 'true', 'width' => '100'),
                array('_id' => 'f9e2e03', 'custom_id' => 'consent', 'field_type' => 'acceptance', 'field_label' => 'Consent', 'acceptance_text' => 'Send me offers and news', 'width' => '100'),
            ),
            'button_text'           => 'Sign up',
            'submit_actions'        => array('agumail'),
            'agumail_tags'          => 'popup, e2e',
            'agumail_double_opt_in' => 'yes',
        ),
        'elements'   => array(),
    )),
));
$document = \Elementor\Plugin::$instance->documents->get($elementor_page, false);
if (!$document || !$document->save(array('elements' => $elements))) {
    WP_CLI::error('Elementor did not save the signup page.');
}
// A saved Elementor page is shown by Elementor, full width, and needs no other content.
update_post_meta($elementor_page, '_elementor_edit_mode', 'builder');
$builders['elementor'] = array(
    'pageId'        => $elementor_page,
    'url'           => get_permalink($elementor_page),
    'formElementId' => $form_element,
    'formName'      => 'AguMail popup',
    'formId'        => $elementor_page . ':' . $form_element,
);

// ── Contact Form 7 ───────────────────────────────────────────────────────────

if (!class_exists('WPCF7_ContactForm')) {
    WP_CLI::error('Contact Form 7 must be active.');
}
$cf7_title = 'AguMail e2e contact';
$cf7_post = agumail_e2e_post_by_title('wpcf7_contact_form', $cf7_title);
$cf7 = $cf7_post ? wpcf7_contact_form($cf7_post->ID) : WPCF7_ContactForm::get_template(array('title' => $cf7_title));
$cf7->set_title($cf7_title);
$cf7->set_properties(array(
    'form'           => "<label> Your name\n    [text* your-name autocomplete:name] </label>\n\n<label> Your email\n    [email* your-email autocomplete:email] </label>\n\n[acceptance newsletter optional consent_for:agumail] Send me offers and news [/acceptance]\n\n[submit \"Sign up\"]",
    'agumail_signup' => array(
        'enabled'       => '1',
        'tags'          => 'contact, e2e',
        'double_opt_in' => '',
        'fields'        => array('email' => '', 'first_name' => '', 'last_name' => '', 'full_name' => '', 'phone' => '', 'consent' => ''),
    ),
));
$cf7->save();
$cf7_page = agumail_e2e_builder_page('agumail-e2e-cf7', 'Signup (Contact Form 7)', "<!-- wp:shortcode -->\n" . $cf7->shortcode() . "\n<!-- /wp:shortcode -->");
$builders['cf7'] = array('formId' => (int) $cf7->id(), 'title' => $cf7_title, 'pageId' => $cf7_page, 'url' => get_permalink($cf7_page));

// ── WPForms ──────────────────────────────────────────────────────────────────

if (!function_exists('wpforms_encode')) {
    WP_CLI::error('WPForms must be active.');
}
$wpforms_title = 'AguMail e2e newsletter';
$wpforms_post = agumail_e2e_post_by_title('wpforms', $wpforms_title);
$wpforms_id = $wpforms_post
    ? (int) $wpforms_post->ID
    : (int) wp_insert_post(array('post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => $wpforms_title, 'post_content' => '{}'));
$form_data = array(
    'id'       => (string) $wpforms_id,
    'field_id' => '4',
    'fields'   => array(
        '1' => array('id' => '1', 'type' => 'name', 'label' => 'Name', 'format' => 'first-last', 'size' => 'medium'),
        '2' => array('id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'medium'),
        '3' => array('id' => '3', 'type' => 'checkbox', 'label' => 'Newsletter', 'choices' => array('1' => array('label' => 'Send me offers and news', 'value' => '', 'image' => ''))),
    ),
    'settings' => array(
        'form_title'             => $wpforms_title,
        'form_desc'              => '',
        'submit_text'            => 'Sign up',
        'submit_text_processing' => 'Sending...',
        'ajax_submit'            => '1',
        'antispam'               => '0',
        'antispam_v3'            => '0',
        'notification_enable'    => '0',
        'confirmations'          => array('1' => array('type' => 'message', 'message' => '<p>Thanks for signing up!</p>', 'message_scroll' => '1')),
        'agumail_enable'         => '1',
        'agumail_tags'           => 'news, e2e',
        'agumail_double_opt_in'  => '1',
        'agumail_saved'          => '1',
        'agumail_field_consent'  => '3',
    ),
    'meta'     => array('template' => 'blank'),
);
wp_update_post(array('ID' => $wpforms_id, 'post_title' => $wpforms_title, 'post_status' => 'publish', 'post_content' => wpforms_encode($form_data)));
$wpforms_page = agumail_e2e_builder_page('agumail-e2e-wpforms', 'Signup (WPForms)', "<!-- wp:shortcode -->\n[wpforms id=\"{$wpforms_id}\" title=\"false\"]\n<!-- /wp:shortcode -->");
$builders['wpforms'] = array('formId' => $wpforms_id, 'title' => $wpforms_title, 'pageId' => $wpforms_page, 'url' => get_permalink($wpforms_page));

file_put_contents('/e2e/state/builders.json', wp_json_encode($builders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
@chmod('/e2e/state/builders.json', 0666);
WP_CLI::success('Signup forms ready: ' . implode(', ', array_keys($builders)));
