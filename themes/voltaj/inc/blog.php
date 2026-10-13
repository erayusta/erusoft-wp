<?php
/**
 * Blog helpers: table of contents built from the post's H2/H3 headings, and reading time.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Add ids to the H2/H3 headings of rendered content and collect them.
 * Existing ids are kept; headings shorter than three characters are skipped.
 *
 * @param string $content Rendered post content.
 * @return array{0:array<int,array{id:string,text:string,level:int}>,1:string} [items, content]
 */
function voltaj_build_toc($content) {
	$items = array();
	$used  = array();
	$content = preg_replace_callback(
		'#<h([23])([^>]*)>(.*?)</h\1>#si',
		static function ($m) use (&$items, &$used) {
			$text = trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8'));
			if (mb_strlen($text) < 3) {
				return $m[0];
			}
			$attrs = $m[2];
			if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs, $found)) {
				$id = $found[1];
			} else {
				$base = sanitize_title(mb_substr($text, 0, 60));
				$base = $base !== '' ? $base : 'bolum';
				$id   = $base;
				$n    = 2;
				while (isset($used[$id])) {
					$id = $base . '-' . $n++;
				}
				$attrs .= ' id="' . esc_attr($id) . '"';
			}
			$used[$id] = true;
			$items[]   = array('id' => $id, 'text' => $text, 'level' => (int) $m[1]);
			return '<h' . $m[1] . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
		},
		$content
	);
	return array($items, (string) $content);
}

/**
 * Table of contents markup (nothing when there are fewer headings than the minimum).
 *
 * @param array $items From voltaj_build_toc().
 */
function voltaj_toc($items) {
	$min = (int) apply_filters('voltaj_toc_min_headings', 2);
	if (count($items) < $min) {
		return;
	}
	?>
	<nav class="vj-toc" aria-labelledby="vj-toc-title">
		<h2 class="vj-toc__title" id="vj-toc-title"><?php voltaj_icon('list'); ?><?php esc_html_e('İçindekiler', 'voltaj'); ?></h2>
		<ol class="vj-toc__list">
			<?php foreach ($items as $item) : ?>
				<li class="vj-toc__item is-h<?php echo (int) $item['level']; ?>"><a href="#<?php echo esc_attr($item['id']); ?>"><?php echo esc_html($item['text']); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<?php
}

/**
 * Reading time in minutes (about 200 words a minute).
 *
 * @param WP_Post|int $post
 * @return int
 */
function voltaj_reading_time($post) {
	$post = get_post($post);
	if (!$post) {
		return 1;
	}
	$words = count(preg_split('/\s+/u', trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content))), -1, PREG_SPLIT_NO_EMPTY));
	$wpm   = max(60, (int) apply_filters('voltaj_words_per_minute', 200));
	return max(1, (int) ceil($words / $wpm));
}
