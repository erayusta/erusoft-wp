<?php
/**
 * Ufuk demo content (idempotent). Run from the repository root with the theme active:
 *
 *   wp theme activate ufuk
 *   wp eval-file themes/ufuk/demo/setup.php
 *
 * Creates a fictional firm ("Ufuk Demo Danışmanlık Ltd. Şti."), pages, three posts, menus, images and
 * Customizer values. Everything it creates is flagged with the _ufuk_demo meta so demo/reset.php can remove
 * exactly that. All names, addresses and numbers are invented.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}
if (get_template() !== 'ufuk') {
	WP_CLI::error('Activate the Ufuk theme first: wp theme activate ufuk');
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const UFUK_DEMO_STATE = 'ufuk_demo_state';

/**
 * Remember the site options the demo changes (first run only), so reset.php can restore them.
 */
function ufuk_demo_remember_state() {
	if (get_option(UFUK_DEMO_STATE)) {
		return;
	}
	$keys  = array('show_on_front', 'page_on_front', 'page_for_posts', 'blogname', 'blogdescription', 'posts_per_page');
	$state = array('options' => array(), 'theme_mods' => array(), 'drafted_posts' => array());
	foreach ($keys as $key) {
		$state['options'][$key] = get_option($key);
	}
	// Pre-existing Ufuk theme mods and menu locations are restored by reset.php instead of being deleted.
	foreach ((array) get_theme_mods() as $key => $value) {
		if (strpos((string) $key, 'ufuk_') === 0) {
			$state['theme_mods'][$key] = $value;
		}
	}
	$state['nav_menu_locations'] = get_theme_mod('nav_menu_locations', null);
	add_option(UFUK_DEMO_STATE, $state, '', false);
}

/**
 * Find a demo post by its key.
 *
 * @param string $key
 * @param string $type
 * @return int
 */
function ufuk_demo_find($key, $type) {
	$found = get_posts(array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_ufuk_demo',
		'meta_value'     => $key,
	));
	return $found ? (int) $found[0] : 0;
}

/**
 * Create or update a demo post/page.
 *
 * @param string $key  Stable key.
 * @param array  $args wp_insert_post args.
 * @return int
 */
function ufuk_demo_post($key, $args) {
	$args = array_merge(array('post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed'), $args);
	$id   = ufuk_demo_find($key, $args['post_type']);
	if ($id) {
		$args['ID'] = $id;
	}
	$id = wp_insert_post(wp_slash($args), true);
	if (is_wp_error($id)) {
		WP_CLI::error($id->get_error_message());
	}
	update_post_meta($id, '_ufuk_demo', $key);
	return (int) $id;
}

/**
 * Import a demo image once.
 *
 * @param string $file  File name in demo/images.
 * @param string $alt   Alt text.
 * @return int Attachment ID.
 */
function ufuk_demo_image($file, $alt) {
	$key = 'image:' . $file;
	$id  = ufuk_demo_find($key, 'attachment');
	if (!$id) {
		$src = __DIR__ . '/images/' . $file;
		$tmp = wp_tempnam($file);
		copy($src, $tmp);
		$id = media_handle_sideload(array('name' => 'ufuk-demo-' . $file, 'tmp_name' => $tmp), 0);
		if (is_wp_error($id)) {
			WP_CLI::error($file . ': ' . $id->get_error_message());
		}
		update_post_meta($id, '_ufuk_demo', $key);
	}
	update_post_meta($id, '_wp_attachment_image_alt', $alt);
	return (int) $id;
}

/**
 * Find a term this demo created earlier (flagged with _ufuk_demo = $key).
 *
 * @param string $key
 * @param string $taxonomy
 * @return int
 */
function ufuk_demo_find_term($key, $taxonomy) {
	$terms = get_terms(array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
		'number'     => 1,
		'meta_key'   => '_ufuk_demo',
		'meta_value' => $key,
	));
	return !is_wp_error($terms) && $terms ? (int) $terms[0] : 0;
}

/**
 * Get the demo's own term, or create it. A user's term with the same slug is never adopted: the demo then
 * creates its own term under a unique slug. Only terms created here get the _ufuk_demo flag.
 *
 * @param string $name
 * @param string $taxonomy
 * @param string $slug
 * @return int
 */
function ufuk_demo_term($name, $taxonomy, $slug) {
	$key = 'term:' . $taxonomy . ':' . $slug;
	$id  = ufuk_demo_find_term($key, $taxonomy);
	if ($id) {
		return $id;
	}
	$try = $slug;
	for ($n = 2; get_term_by('slug', $try, $taxonomy); $n++) {
		$try = $slug . '-ufuk-demo' . ($n > 2 ? '-' . $n : '');
	}
	$res = wp_insert_term($name, $taxonomy, array('slug' => $try));
	if (is_wp_error($res)) {
		WP_CLI::error($res->get_error_message());
	}
	update_term_meta((int) $res['term_id'], '_ufuk_demo', $key);
	return (int) $res['term_id'];
}

/**
 * Build the demo's own menu (items are recreated on every run). A user's menu with the same name is never
 * touched: the demo then creates its own menu under a unique name.
 *
 * @param string $name
 * @param array  $items list of [title, page_id, children[]]
 * @return int
 */
function ufuk_demo_menu($name, $items) {
	$key     = 'menu:' . $name;
	$menu_id = ufuk_demo_find_term($key, 'nav_menu');
	if ($menu_id) {
		foreach ((array) wp_get_nav_menu_items($menu_id, array('post_status' => 'any')) as $item) {
			wp_delete_post($item->ID, true);
		}
	} else {
		$try = $name;
		for ($n = 2; wp_get_nav_menu_object($try); $n++) {
			$try = $name . ' (Ufuk demo' . ($n > 2 ? ' ' . $n : '') . ')';
		}
		$menu_id = wp_create_nav_menu($try);
		if (is_wp_error($menu_id)) {
			WP_CLI::error($menu_id->get_error_message());
		}
		$menu_id = (int) $menu_id;
		update_term_meta($menu_id, '_ufuk_demo', $key);
	}
	$order = 1;
	foreach ($items as $item) {
		$parent = wp_update_nav_menu_item($menu_id, 0, array(
			'menu-item-title'     => $item[0],
			'menu-item-object-id' => $item[1],
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $order++,
		));
		foreach (isset($item[2]) ? $item[2] : array() as $child) {
			wp_update_nav_menu_item($menu_id, 0, array(
				'menu-item-title'     => $child[0],
				'menu-item-object-id' => $child[1],
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
				'menu-item-position'  => $order++,
			));
		}
	}
	return $menu_id;
}

/**
 * Block markup helpers.
 */
function ufuk_demo_p($text) {
	return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
}
function ufuk_demo_h($text) {
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $text . "</h2>\n<!-- /wp:heading -->\n\n";
}
function ufuk_demo_list($items) {
	$li = '';
	foreach ($items as $item) {
		$li .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n";
	}
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list -->\n\n";
}

ufuk_demo_remember_state();

/* ── Keep the listings demo-only: unpublish other published posts (reset.php republishes them) ── */
$ufuk_state = get_option(UFUK_DEMO_STATE);
$ufuk_other = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => '_ufuk_demo', 'compare' => 'NOT EXISTS'))));
if ($ufuk_other) {
	$ufuk_drafted = isset($ufuk_state['drafted_posts']) ? (array) $ufuk_state['drafted_posts'] : array();
	foreach ($ufuk_other as $ufuk_pid) {
		wp_update_post(array('ID' => $ufuk_pid, 'post_status' => 'draft'));
		// Remember when we drafted it: reset.php republishes only if nobody edited it since.
		$ufuk_drafted[$ufuk_pid] = get_post_field('post_modified_gmt', $ufuk_pid, 'raw');
	}
	$ufuk_state['drafted_posts'] = $ufuk_drafted;
	update_option(UFUK_DEMO_STATE, $ufuk_state, false);
}

/* ── Images ── */
$img_hero    = ufuk_demo_image('hero.jpg', 'Gri tonlarda modern bir binanın açılı cephesi');
$img_office  = ufuk_demo_image('ofis.jpg', 'Beyaz masaları ve siyah sandalyeleri olan aydınlık, boş bir ofis odası');
$img_meeting = ufuk_demo_image('toplanti-odasi.jpg', 'Geniş pencereli, boş bir toplantı salonu');
$img_cubes   = ufuk_demo_image('soyut-kupler.jpg', 'Koyu mavi tonlarda soyut küp biçimleri');
$img_facade  = ufuk_demo_image('mimari-cephe.jpg', 'Mavi gökyüzüne uzanan kıvrımlı beyaz bina cepheleri');

/* ── Pages ── */
$home = ufuk_demo_post('page:home', array(
	'post_type'    => 'page',
	'post_title'   => 'Ana Sayfa',
	'post_name'    => 'ana-sayfa',
	'post_content' => '',
));

$about = ufuk_demo_post('page:about', array(
	'post_type'    => 'page',
	'post_title'   => 'Hakkımızda',
	'post_name'    => 'hakkimizda',
	'menu_order'   => 1,
	'post_excerpt' => 'Ufuk Demo Danışmanlık, işletmelerin hedeflerini sade bir plana dönüştürmesine yardım eden kurgusal bir danışmanlık firmasıdır. Bu sayfa, temanın nasıl göründüğünü göstermek için hazırlandı.',
	'post_content' =>
		ufuk_demo_p('Ufuk Demo Danışmanlık, Ufuk temasının demo sitesi için tasarlanmış <strong>kurgusal</strong> bir firmadır. Burada okuduğunuz unvan, adres, telefon ve e-posta bilgileri gerçek değildir.') .
		ufuk_demo_h('Nasıl çalışırız?') .
		ufuk_demo_p('Her çalışmaya mevcut durumu birlikte yazıya dökerek başlarız. Ardından öncelikleri belirler, küçük ve ölçülebilir adımlardan oluşan bir plan hazırlarız.') .
		ufuk_demo_list(array('Kısa bir ön görüşme ile ihtiyacı netleştirmek', 'Yazılı bir kapsam ve zaman planı paylaşmak', 'Her adımın sonunda birlikte değerlendirme yapmak')) .
		ufuk_demo_h('Değerlerimiz') .
		ufuk_demo_p('Açık iletişim, yazılı kararlar ve gerçekçi takvimler. Söz veremeyeceğimiz bir sonucu vaat etmeyiz.'),
));
set_post_thumbnail($about, $img_office);

$services = ufuk_demo_post('page:services', array(
	'post_type'    => 'page',
	'post_title'   => 'Hizmetler',
	'post_name'    => 'hizmetler',
	'menu_order'   => 2,
	'post_content' =>
		ufuk_demo_p('Aşağıdaki başlıklar demo içeriğidir. Kendi sitenizde her hizmeti bu sayfanın alt sayfası olarak ekleyin; tema onları burada ve ana sayfada kart olarak listeler.'),
));

$service_pages = array(
	array('strateji', 'Strateji ve Yol Haritası', 'Hedeflerinizi önceliklere ayırıp üç ila on iki aylık, takip edilebilir bir plana dönüştürüyoruz.', 'Ön görüşmede hedefleri ve kısıtları birlikte listeleriz. Ardından her hedef için sorumlu, takvim ve başarı ölçütü içeren kısa bir yol haritası hazırlarız.'),
	array('surec', 'Süreç İyileştirme', 'Tekrarlayan işleri adım adım yazıya döküp gereksiz aşamaları sadeleştiriyoruz.', 'Mevcut akışı ekiple birlikte çizeriz, darboğazları işaretleriz ve değişiklikleri küçük denemelerle hayata geçiririz.'),
	array('egitim', 'Eğitim ve Atölyeler', 'Ekiplerin yeni çalışma biçimlerini birlikte denediği uygulamalı oturumlar düzenliyoruz.', 'Atölyeler gerçek iş örnekleri üzerinden ilerler. Oturum sonunda katılımcılar, kendi işlerine uygulayabilecekleri bir kontrol listesiyle ayrılır.'),
	array('proje', 'Proje Yönetimi Desteği', 'Başlamış projelerde takvim, kapsam ve iletişim düzenini toparlamaya yardım ediyoruz.', 'Haftalık kısa toplantılar, ortak bir görev panosu ve düzenli durum notlarıyla projenin görünürlüğünü artırırız.'),
);
$service_ids = array();
foreach ($service_pages as $i => $s) {
	$service_ids[] = array($s[1], ufuk_demo_post('page:service:' . $s[0], array(
		'post_type'    => 'page',
		'post_title'   => $s[1],
		'post_name'    => $s[0] === 'strateji' ? 'strateji-ve-yol-haritasi' : ($s[0] === 'surec' ? 'surec-iyilestirme' : ($s[0] === 'egitim' ? 'egitim-ve-atolyeler' : 'proje-yonetimi-destegi')),
		'post_parent'  => $services,
		'menu_order'   => $i + 1,
		'post_excerpt' => $s[2],
		'post_content' => ufuk_demo_p($s[2]) . ufuk_demo_h('Çalışma biçimi') . ufuk_demo_p($s[3]),
	)));
}

$contact = ufuk_demo_post('page:contact', array(
	'post_type'     => 'page',
	'post_title'    => 'İletişim',
	'post_name'     => 'iletisim',
	'menu_order'    => 4,
	'page_template' => 'page-templates/contact.php',
	'post_content'  => ufuk_demo_p('Sorularınızı formdan iletebilirsiniz. Demo sitede gönderilen mesajlar kimseye ulaşmaz.'),
));
update_post_meta($contact, '_wp_page_template', 'page-templates/contact.php');

$blog = ufuk_demo_post('page:blog', array(
	'post_type'  => 'page',
	'post_title' => 'Blog',
	'post_name'  => 'blog',
	'menu_order' => 3,
));

/* ── Posts ── */
$cat = ufuk_demo_term('Notlar', 'category', 'notlar');
$posts = array(
	array('yol-haritasi', 'Yol haritası hazırlarken sorulacak beş soru', $img_meeting, '-9 days',
		'Bir planın işe yaraması için önce doğru soruları sormak gerekir. Toplantıya girmeden önce yanıtlamanızı önerdiğimiz beş soru.',
		ufuk_demo_p('Bir yol haritası, ne yapılacağından çok neyin yapılmayacağını da söyler. Aşağıdaki sorular, önceliklerin daha ilk toplantıda netleşmesine yardım eder.') .
		ufuk_demo_h('1. Bu yıl neyi farklı yapmak istiyoruz?') .
		ufuk_demo_p('Tek cümlelik, ölçülebilir bir yanıt arayın. "Daha iyi olmak" yerine "teklif hazırlama süresini kısaltmak" gibi.') .
		ufuk_demo_h('2. Bunu kim sahiplenecek?') .
		ufuk_demo_p('Her hedefin bir sorumlusu olmalı. Sorumlusu olmayan hedef, takvimden ilk düşen hedeftir.') .
		ufuk_demo_h('3–5. Kaynak, takvim ve vazgeçilecekler') .
		ufuk_demo_list(array('Hangi bütçe ve zaman ayrılabilir?', 'İlk somut çıktı ne zaman görülecek?', 'Bu hedef için hangi işi bırakıyoruz?')),
	),
	array('surec-sadelestirme', 'Süreçleri sadeleştirmenin ilk adımı: mevcut durumu yazmak', $img_cubes, '-5 days',
		'İyileştirmeye başlamadan önce bugün işlerin gerçekte nasıl yürüdüğünü yazıya dökmek, sürprizleri en baştan ortaya çıkarır.',
		ufuk_demo_p('Çoğu süreç, yazılı hâliyle uygulanan hâli arasında fark taşır. Bu farkı görmenin en kolay yolu, işi yapan kişilerle birlikte adımları sırayla yazmaktır.') .
		ufuk_demo_h('Basit bir şablon') .
		ufuk_demo_list(array('Süreci başlatan olay nedir?', 'Hangi adımlar, hangi sırayla, kim tarafından yapılıyor?', 'Nerede bekleme ya da tekrar oluyor?', 'Süreç ne zaman tamamlanmış sayılıyor?')) .
		ufuk_demo_p('Bu liste hazır olduğunda, hangi adımın sadeleştirileceği çoğu zaman kendiliğinden belli olur.'),
	),
	array('atolye-secimi', 'Uzaktan ve yüz yüze atölyeler arasında seçim yapmak', $img_facade, '-2 days',
		'İki biçimin de güçlü yanları var. Katılımcı sayısı, konunun niteliği ve takvim, hangisinin daha uygun olduğunu belirler.',
		ufuk_demo_p('Yüz yüze atölyeler, birlikte çizim yapılan ve hızlı karar alınan oturumlar için idealdir. Uzaktan oturumlar ise farklı şehirlerdeki ekipleri aynı anda bir araya getirir.') .
		ufuk_demo_h('Kısa bir karar listesi') .
		ufuk_demo_list(array('Katılımcılar aynı şehirde mi?', 'Oturumda ortak bir tasarım ya da çizim yapılacak mı?', 'Oturum birden fazla güne bölünebilir mi?')) .
		ufuk_demo_p('Karma bir düzen de mümkündür: hazırlık uzaktan, karar oturumu yüz yüze yapılabilir.'),
	),
);
foreach ($posts as $p) {
	$id = ufuk_demo_post('post:' . $p[0], array(
		'post_type'     => 'post',
		'post_title'    => $p[1],
		'post_name'     => $p[0],
		'post_excerpt'  => $p[4],
		'post_content'  => $p[5],
		'post_date'     => wp_date('Y-m-d H:i:s', strtotime($p[3])),
		'post_category' => array($cat),
	));
	set_post_thumbnail($id, $p[2]);
}

/* ── Reading settings ── */
update_option('blogname', 'Ufuk Demo Danışmanlık');
update_option('blogdescription', 'Strateji, süreç ve ekip gelişimi');
update_option('show_on_front', 'page');
update_option('page_on_front', $home);
update_option('page_for_posts', $blog);

/* ── Menus ── */
$children = array();
foreach ($service_ids as $s) {
	$children[] = array($s[0], $s[1]);
}
$primary = ufuk_demo_menu('Ufuk Demo Ana Menü', array(
	array('Ana Sayfa', $home),
	array('Hakkımızda', $about),
	array('Hizmetler', $services, $children),
	array('Blog', $blog),
	array('İletişim', $contact),
));
$footer = ufuk_demo_menu('Kurumsal', array(
	array('Hakkımızda', $about),
	array('Hizmetler', $services),
	array('Blog', $blog),
	array('İletişim', $contact),
));
$locations            = (array) get_theme_mod('nav_menu_locations', array());
$locations['primary'] = $primary;
$locations['footer']  = $footer;
set_theme_mod('nav_menu_locations', $locations);

/* ── Customizer values ── */
$phone = '0312' . ' 000 00 00'; // invented; split so it is obviously not a real listing
$mods  = array(
	'header_cta_text'   => 'Bize ulaşın',
	'header_cta_url'    => get_permalink($contact),
	'hero_kicker'       => 'Kurumsal danışmanlık',
	'hero_title'        => 'Planınızı birlikte netleştirelim',
	'hero_text'         => 'Strateji, süreç ve ekip gelişimi konularında sade, yazılı ve uygulanabilir adımlar öneriyoruz.',
	'hero_image'        => $img_hero,
	'hero_button_text'  => 'Görüşme planlayın',
	'hero_button_url'   => get_permalink($contact),
	'hero_button2_text' => 'Hizmetler',
	'hero_button2_url'  => get_permalink($services),
	'stat_1_value'      => '4',
	'stat_1_label'      => 'Hizmet alanı',
	'stat_2_value'      => '09:00–18:00',
	'stat_2_label'      => 'Hafta içi çalışma saatleri',
	'stat_3_value'      => '1 iş günü',
	'stat_3_label'      => 'İçinde yanıt hedefi',
	'stat_4_value'      => 'Çankaya',
	'stat_4_label'      => 'Ankara ofisi',
	'services_page'     => $services,
	'services_intro'    => 'Her çalışma, ihtiyacınızı dinlediğimiz kısa bir ön görüşmeyle başlar.',
	'about_page'        => $about,
	'about_points'      => "Yazılı kapsam ve zaman planı\nHer adımın sonunda ortak değerlendirme\nEkibinizle birlikte çalışan küçük bir ekip\nGerçekçi takvim, abartısız vaat",
	'posts_title'       => 'Notlar',
	'posts_count'       => 3,
	'cta_title'         => 'Bir ön görüşme planlayalım',
	'cta_text'          => 'İhtiyacınızı kısaca anlatın; size uygun bir başlangıç adımı önerelim.',
	'cta_button_text'   => 'İletişime geçin',
	'cta_button_url'    => get_permalink($contact),
	'company_name'      => 'Ufuk Demo Danışmanlık Ltd. Şti.',
	'contact_email'     => 'ornek@example.com',
	'contact_phone'     => $phone,
	'contact_address'   => "Örnek Mah. Demo Cad. No: 1\nÇankaya / Ankara",
	'contact_hours'     => 'Hafta içi 09:00–18:00',
	'contact_page'      => $contact,
	'footer_text'       => 'Bu site Ufuk temasının demo içeriğidir. Firma, adres ve iletişim bilgileri kurgusaldır.',
);
foreach ($mods as $key => $value) {
	set_theme_mod('ufuk_' . $key, $value);
}

WP_CLI::success(sprintf('Ufuk demo ready: %d pages, %d posts, 5 images, 2 menus.', 4 + count($service_ids) + 1, count($posts)));
