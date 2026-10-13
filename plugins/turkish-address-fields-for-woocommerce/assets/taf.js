/**
 * Turkish Address Fields — İl → İlçe → Mahalle → Cadde/Sokak cascade for the classic checkout
 * and My Account address forms (billing_ and shipping_ groups share one data cache).
 */
(function ($) {
	'use strict';

	var cfg = window.eruTaf;
	if (!cfg) { return; }
	var t = cfg.i18n || {};
	var OTHER = cfg.other;
	var cache = {};

	/** GET the bundled data through admin-ajax; one request per distinct query, cached in memory and by the browser. */
	function get(params) {
		var key = $.param(params);
		if (!cache[key]) {
			cache[key] = $.ajax({ url: cfg.ajax, data: $.extend({ action: 'eru_taf_data', v: cfg.v }, params), dataType: 'json', cache: true })
				.then(function (r) { return r && r.success ? r.data : []; }, function () {
					delete cache[key];
					return $.Deferred().resolve([]).promise();
				});
		}
		return cache[key];
	}

	function fill($sel, items, placeholder, valueFn, labelFn, withOther) {
		if (!$sel.length) { return; }
		$sel.empty().append($('<option>', { value: '', text: placeholder }));
		(items || []).forEach(function (it) {
			$sel.append($('<option>', { value: valueFn(it), text: labelFn(it) }));
		});
		if (withOther) { $sel.append($('<option>', { value: OTHER, text: t.other })); }
		$sel.trigger('change.select2');
	}

	function pick($sel, wanted) {
		if (!wanted || !$sel.length) { return false; }
		var hit = $sel.find('option').filter(function () { return this.value === wanted; }).first();
		if (!hit.length) { return false; }
		$sel.val(wanted).trigger('change');
		return true;
	}

	function setup(g) {
		var $district = $('#' + g + '_eru_taf_district');
		if (!$district.length) { return null; }
		var $hood = $('#' + g + '_eru_taf_neighborhood');
		var $street = $('#' + g + '_eru_taf_street');
		var ids = { district: {}, hood: {} };
		var pending = $.extend({}, (cfg.saved && cfg.saved[g]) || {});
		var $rows = $('#' + g + '_eru_taf_district_field, #' + g + '_eru_taf_neighborhood_field, #' + g + '_eru_taf_street_field, #' + g + '_eru_taf_no_number_field');

		function country() {
			var $c = $('#' + g + '_country');
			return $c.length ? ($c.val() || '') : 'TR';
		}
		function plate() {
			var m = /^TR(\d{2})$/.exec($('#' + g + '_state').val() || '');
			return m ? m[1] : null;
		}
		function cityRow(show) {
			var $row = $('#' + g + '_city_field');
			if (show) {
				$row.show();
				$('#' + g + '_city').val('').attr('placeholder', t.districtByHand).trigger('focus');
			} else if (country() === 'TR') {
				$row.hide();
			}
		}
		function reset(level) {
			if (level <= 1) { fill($district, [], t.waitProvince); }
			if (level <= 2) { fill($hood, [], t.waitDistrict); }
			if (level <= 3) { fill($street, [], t.waitNeighborhood); }
		}

		function onCountry() {
			var tr = country() === 'TR';
			$rows.toggle(tr);
			if (!tr) { reset(1); }
		}

		function onState() {
			if (country() !== 'TR') { return; }
			var p = plate();
			if (!p) { reset(1); return; }
			$district.find('option[value=""]').text(t.loading);
			get({ level: 'districts', il: p }).then(function (rows) {
				if (plate() !== p) { return; }
				ids.district = {};
				rows.forEach(function (r) { ids.district[r[1]] = r[0]; });
				fill($district, rows, t.pickDistrict, function (r) { return r[1]; }, function (r) { return r[1]; }, true);
				reset(2);
				var want = pending.district; pending.district = null;
				if (!pick($district, want)) { cityRow(false); }
			});
		}

		function onDistrict() {
			var name = $district.val();
			reset(2);
			if (name === OTHER) {
				cityRow(true);
				fill($hood, [], t.writeBelow, null, null, true);
				return;
			}
			cityRow(false);
			if (!name) { return; }
			$('#' + g + '_city').val(name);
			if (!$hood.length) { return; }
			var id = ids.district[name];
			if (!id) { return; }
			$hood.find('option[value=""]').text(t.loading);
			get({ level: 'neighborhoods', district: id }).then(function (rows) {
				if ($district.val() !== name) { return; }
				ids.hood = {};
				rows.forEach(function (r) { ids.hood[r[1]] = r[0]; });
				fill($hood, rows, t.pickNeighborhood, function (r) { return r[1]; }, function (r) { return r[1]; }, true);
				reset(3);
				var want = pending.neighborhood; pending.neighborhood = null;
				pick($hood, want);
			});
		}

		function onHood() {
			if (!$street.length) { return; }
			var name = $hood.val();
			var did = ids.district[$district.val()];
			if (!name || name === OTHER || !did) {
				fill($street, [], name === OTHER ? t.writeBelow : t.waitNeighborhood, null, null, name === OTHER);
				return;
			}
			$street.find('option[value=""]').text(t.loading);
			get({ level: 'streets', district: did, neighborhood: ids.hood[name] }).then(function (rows) {
				if ($hood.val() !== name) { return; }
				fill($street, rows, rows.length ? t.pickStreet : t.noStreets, String, String, true);
				var want = pending.street; pending.street = null;
				if (want && !pick($street, want)) { $street.val(''); }
			});
		}

		$(document.body)
			.on('change', '#' + g + '_country', onCountry)
			.on('change', '#' + g + '_state', onState)
			.on('change', '#' + g + '_eru_taf_district', onDistrict)
			.on('change', '#' + g + '_eru_taf_neighborhood', onHood)
			.on('country_to_state_changed', function () { onCountry(); });

		return { start: function () { onCountry(); onState(); } };
	}

	/** Searchable selects (selectWoo). */
	function enhance() {
		var plugin = $.fn.selectWoo ? 'selectWoo' : ($.fn.select2 ? 'select2' : null);
		if (!plugin) { return; }
		var lang = { noResults: function () { return t.noResults; }, searching: function () { return t.searching; } };
		cfg.groups.forEach(function (g) {
			['district', 'neighborhood', 'street'].forEach(function (f) {
				var $el = $('#' + g + '_eru_taf_' + f);
				if (!$el.length || $el.hasClass('select2-hidden-accessible')) { return; }
				// WooCommerce prints data-placeholder on selects with an empty option; Select2 freezes it at init
				// and would keep showing "Önce il seçin" after the list is filled. Let the option text show instead.
				$el.removeAttr('data-placeholder data-allow_clear');
				$el[plugin]({ width: '100%', language: lang, minimumResultsForSearch: 8 });
			});
		});
	}

	$(function () {
		var sections = [];
		cfg.groups.forEach(function (g) {
			var s = setup(g);
			if (s) { sections.push(s); }
		});
		if (!sections.length) { return; }
		enhance();
		sections.forEach(function (s) { s.start(); });
	});
})(jQuery);
