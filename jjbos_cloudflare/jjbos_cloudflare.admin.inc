<?php

/**
 * @file
 * Admin page callback file for the  JJBos Cloudflate GeoIP Redirects module.
 */

/**
 * Form callback for settings form.
 */
function jjbos_cloudflare_config_form($form, &$form_state) {
  $redirects = variable_get('jjbos_cloudflare_redirects', array());

  $form['jjbos_cloudflare_ajax'] = array(
    '#type' => 'checkbox',
    '#title' => t('Get GeoIP code from server, if cookie not found.'),
    '#description' => t(
      'If this option is enabled, and cookie with GeoIP code not found in client browser, AJAX request to server is sent to obtain the code.'
    ),
    '#default_value' => variable_get('jjbos_cloudflare_ajax', FALSE),
  );

  $form['jjbos_cloudflare_redirects'] = array(
    '#type' => 'textarea',
    '#title' => t('JJBos Cloudflare GeoIP Redirects'),
    '#default_value' => list_allowed_values_string($redirects),
    '#rows' => 10,
    '#element_validate' => array('jjbos_cloudflare_redirects_validate'),
  );

  $description = '<p>' . t('The list of JJBos Cloudflare GeoIP Redirects. Enter one value per line, in the format "code|URL".');
  $description .= '<br/>' . t('The key is the area code, got from Cloudflare geolocation header.');
  $description .= '<br/>' . t('The URL is valid external URL to redirect (starting with http:// or https://).');
  $description .= '</p>';
  $description .= '<h4>' . t('Please be advised that after changing these settings, you must clear all pages from the Drupal page cache, Varnish and CDN.');
  $description .= '</h4>';
  $form['jjbos_cloudflare_redirects']['#description'] = $description;

  $form['#submit'][] = 'jjbos_cloudflare_redirects_values_translate_submit';

  return system_settings_form($form);
}

/**
 * Element jjbos_cloudflare_redirects validation callback.
 */
function jjbos_cloudflare_redirects_validate($element, &$form_state, $form) {
  $values = list_extract_allowed_values($element['#value'], 'list_text', FALSE);

  foreach ($values as $key => $value) {
    if (empty($key) || empty($value)) {
      form_error($element, t('Some codes or URLs are empty in the field.'));
      break;
    }
    if (!valid_url($value, TRUE)) {
      form_error($element, t('The field contains invalid URL to redirect.'));
      break;
    }
    if (!empty($key) && ((bool) preg_match('/[a-z]/', $key))) {
      form_error($element, t('Country codes must only contain uppercase characters.'));
      break;
    }
  }
}

/**
 * Submit callback for jjbos_cloudflare_config_form.
 */
function jjbos_cloudflare_redirects_values_translate_submit($form, &$form_state) {
  $value = &$form_state['values']['jjbos_cloudflare_redirects'];
  $value = list_extract_allowed_values($value, 'list_text', FALSE);
}
