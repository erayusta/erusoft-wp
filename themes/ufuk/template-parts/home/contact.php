<?php
/**
 * Home: contact block.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_contact')) {
	return;
}
get_template_part('template-parts/contact-block', null, array('heading' => true));
