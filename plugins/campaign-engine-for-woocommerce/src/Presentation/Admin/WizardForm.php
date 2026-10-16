<?php
/**
 * The campaign wizard's form, both ways: posted fields → engine definition (parse) and definition →
 * form fields (fromDefinition), so what the merchant saw is what is saved and saving twice changes
 * nothing.
 *
 * Arrays stay arrays: target ids arrive as a list or a comma-separated string and every id is checked
 * (a malformed id is an error, never dropped, because dropping it could make the campaign storewide);
 * the condition tree arrives as JSON and is decoded as a tree. Nothing here runs a whole array through
 * a text sanitiser. Amounts are typed in the store currency ("1.250,00", "1250.5") and stored in minor
 * units; a value that is not an amount is an error, never zero.
 *
 * Actions and meta keys the form has no field for (a campaign created through code) travel in hidden
 * JSON fields, so opening and saving such a campaign keeps them.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use DateTimeImmutable;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Campaign\Recurrence;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;
use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;

final class WizardForm {

	public const TYPES = array( 'product_percent', 'product_fixed', 'product_price', 'cart_percent', 'cart_fixed', 'tiered', 'set_reward', 'free_shipping', 'future_reward' );

	/** Action types that discount cart lines (the others give free shipping or a later reward). */
	public const LINE_TYPES = array( 'product_percent', 'product_fixed', 'product_price', 'cart_percent', 'cart_fixed', 'tiered', 'set_reward' );

	/** Conditions whose value is an amount (typed in the store currency, stored in minor units). */
	public const MONEY_CONDITIONS = array( 'cart.subtotal', 'cart.eligible_subtotal', 'item.price', 'customer.total_spent' );

	/** Conditions whose value is a whole number or a list of ids. */
	public const INT_CONDITIONS = array( 'cart.quantity', 'cart.eligible_quantity', 'item.quantity', 'item.stock_quantity', 'item.age_days', 'customer.order_count', 'customer.days_since_last_order', 'item.product', 'item.category', 'item.tag', 'item.brand', 'item.term' );

	/** Meta keys with a field of their own. */
	private const META_FIELDS = array( 'badge', 'public', 'description' );

	/**
	 * @param array<string,mixed> $in posted `eru_ce` fields (already unslashed)
	 * @return array{definition:array<string,mixed>,errors:array<string,string>}
	 */
	public static function parse( array $in ): array {
		$errors = array();
		$def    = array();
		$type   = self::str( $in['type'] ?? '' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			$errors['type'] = 'unknown_choice';
			$type           = '';
		}

		$actions = array();

		if ( '' !== $type ) {
			$primary = self::action( $type, $in, $errors );

			if ( null !== $primary ) {
				$actions[] = $primary;
			}
		}

		$extra = self::jsonList( $in['extra_actions'] ?? '', 'extra_actions', $errors );

		foreach ( $extra as $row ) {
			$actions[] = $row;
		}

		$has_shipping = in_array( 'free_shipping', array_column( $actions, 'type' ), true );

		if ( 'yes' === ( $in['also_free_shipping'] ?? '' ) && ! $has_shipping ) {
			$actions[] = array( 'type' => 'free_shipping' );
		}

		$def['actions'] = $actions;
		$rules          = self::rules( self::str( $in['rules_json'] ?? '' ), $errors );

		if ( null !== $rules ) {
			$def['rules'] = $rules;
		}

		$def['targets'] = self::targets( $in, $errors );
		$schedule       = self::schedule( $in, $errors );

		if ( null !== $schedule ) {
			$def['schedule'] = $schedule;
		}

		$priority = self::str( $in['priority'] ?? '' );

		if ( '' === $priority ) {
			$def['priority'] = 10;
		} elseif ( 1 === preg_match( '/^-?\d{1,6}$/', $priority ) ) {
			$def['priority'] = (int) $priority;
		} else {
			$errors['priority'] = 'invalid';
		}

		$def['exclusive'] = 'yes' === ( $in['exclusive'] ?? '' );
		$basis            = self::str( $in['basis'] ?? 'current' );

		if ( in_array( $basis, Campaign::BASES, true ) ) {
			$def['basis'] = $basis;
		} else {
			$errors['basis'] = 'unknown_choice';
		}

		$cap = self::money( $in['max_discount'] ?? '', 'max_discount', $errors, true );

		if ( null !== $cap ) {
			$def['max_discount_minor'] = $cap;
		}

		foreach ( array( 'usage_limit', 'usage_limit_per_customer' ) as $field ) {
			$raw = self::str( $in[ $field ] ?? '' );

			if ( '' === $raw ) {
				continue;
			}

			if ( 1 === preg_match( '/^\d{1,9}$/', $raw ) && (int) $raw > 0 ) {
				$def[ $field ] = (int) $raw;
			} else {
				$errors[ $field ] = 'invalid';
			}
		}

		$def['paused'] = 'yes' === ( $in['paused'] ?? '' );
		$def['meta']   = self::meta( $in, $errors );

		if ( array() === $errors ) {
			$valid = CampaignWriter::validate( $def );

			if ( is_wp_error( $valid ) ) {
				$data                                  = (array) $valid->get_error_data();
				$errors[ self::formField( (string) ( $data['field'] ?? '' ), $type ) ] = (string) ( $data['reason'] ?? 'invalid' );
			}
		}

		return array(
			'definition' => $def,
			'errors'     => $errors,
		);
	}

	/**
	 * Form fields for a stored definition (the inverse of parse()).
	 *
	 * @param array<string,mixed> $def
	 * @return array<string,mixed>
	 */
	public static function fromDefinition( array $def ): array {
		$decimals = self::decimals();
		$actions  = array_values( array_filter( (array) ( $def['actions'] ?? array() ), 'is_array' ) );
		$primary  = $actions[0] ?? array( 'type' => 'product_percent' );
		$rest     = array_slice( $actions, 1 );
		$out      = array( 'type' => (string) ( $primary['type'] ?? 'product_percent' ) );
		$last     = end( $rest );

		if ( is_array( $last ) && 'free_shipping' === ( $last['type'] ?? '' ) && array( 'type' => 'free_shipping' ) === $last ) {
			$out['also_free_shipping'] = 'yes';
			array_pop( $rest );
		}

		$out['extra_actions'] = array() === $rest ? '' : (string) wp_json_encode( $rest, JSON_UNESCAPED_UNICODE );
		$out                 += self::actionFields( $primary, $decimals );
		$out['rules_json']    = isset( $def['rules'] ) && is_array( $def['rules'] ) ? (string) wp_json_encode( self::rulesForForm( $def['rules'], $decimals ), JSON_UNESCAPED_UNICODE ) : '';

		$targets = (array) ( $def['targets'] ?? array() );
		$inc     = (array) ( $targets['include'] ?? array() );
		$exc     = (array) ( $targets['exclude'] ?? array() );

		$out['include_products'] = array_map( 'strval', array_merge( (array) ( $inc['product'] ?? array() ), (array) ( $inc['variation'] ?? array() ) ) );
		$out['include_terms']    = array_map( 'strval', (array) ( $inc['term'] ?? array() ) );
		$out['exclude_products'] = array_map( 'strval', array_merge( (array) ( $exc['product'] ?? array() ), (array) ( $exc['variation'] ?? array() ) ) );
		$out['exclude_terms']    = array_map( 'strval', (array) ( $exc['term'] ?? array() ) );
		$out['sku']              = implode( ', ', array_map( 'strval', (array) ( $inc['sku'] ?? array() ) ) );
		$out['exclude_sku']      = implode( ', ', array_map( 'strval', (array) ( $exc['sku'] ?? array() ) ) );
		$out['basis']            = (string) ( $def['basis'] ?? 'current' );

		$schedule          = is_array( $def['schedule'] ?? null ) ? $def['schedule'] : array();
		$out['starts_at']  = self::localInput( $schedule['starts_ts'] ?? null );
		$out['ends_at']    = self::localInput( $schedule['ends_ts'] ?? null );
		$out['flash']      = ! empty( $schedule['flash'] ) ? 'yes' : '';
		$out['recurrence'] = is_array( $schedule['recurrence'] ?? null ) ? array_map( static fn( $v ) => is_array( $v ) ? array_map( 'strval', $v ) : (string) $v, $schedule['recurrence'] ) : array( 'freq' => 'none' );

		if ( 'monthly' === ( $out['recurrence']['freq'] ?? '' ) ) {
			$out['recurrence']['month_days'] = implode( ', ', (array) ( $out['recurrence']['days'] ?? array() ) );
			unset( $out['recurrence']['days'] );
		}

		$out['priority']                 = (string) ( $def['priority'] ?? 10 );
		$out['exclusive']                = ! empty( $def['exclusive'] ) ? 'yes' : '';
		$out['max_discount']             = isset( $def['max_discount_minor'] ) ? Money::toDecimal( (int) $def['max_discount_minor'], $decimals ) : '';
		$out['usage_limit']              = isset( $def['usage_limit'] ) ? (string) $def['usage_limit'] : '';
		$out['usage_limit_per_customer'] = isset( $def['usage_limit_per_customer'] ) ? (string) $def['usage_limit_per_customer'] : '';
		$out['paused']                   = ! empty( $def['paused'] ) ? 'yes' : '';

		$meta               = is_array( $def['meta'] ?? null ) ? $def['meta'] : array();
		$out['badge']       = (string) ( $meta['badge'] ?? '' );
		$out['public']      = ! empty( $meta['public'] ) ? 'yes' : '';
		$out['description'] = (string) ( $meta['description'] ?? '' );
		$extra_meta         = array_diff_key( $meta, array_flip( self::META_FIELDS ) );
		$out['extra_meta']  = array() === $extra_meta ? '' : (string) wp_json_encode( $extra_meta, JSON_UNESCAPED_UNICODE );

		return $out;
	}

	/**
	 * Non-blocking notes about a valid definition.
	 *
	 * @param array<string,mixed> $def
	 * @return string[]
	 */
	public static function warnings( array $def ): array {
		$out   = array();
		$types = array_map( static fn( $a ): string => is_array( $a ) ? (string) ( $a['type'] ?? '' ) : '', (array) ( $def['actions'] ?? array() ) );

		if ( ! empty( $def['exclusive'] ) && array() === array_intersect( $types, self::LINE_TYPES ) ) {
			$out[] = __( 'Bu kampanya "tek başına" işaretli ama ürünlere indirim yapmıyor (yalnızca ücretsiz kargo veya sonraki alışveriş ödülü veriyor). Uygulandığında, öncelik sırasına göre kendisinden sonra gelen bütün kampanyaları, indirim yapanlar dahil, devre dışı bırakır. Bunu istemiyorsanız "tek başına" seçeneğini kaldırın.', 'campaign-engine-for-woocommerce' );
		}

		return $out;
	}

	/** The form field a writer error belongs to. */
	public static function formField( string $field, string $type = '' ): string {
		if ( 0 === strpos( $field, 'targets.include.' ) ) {
			return str_ends_with( $field, '.term' ) ? 'include_terms' : ( str_ends_with( $field, '.sku' ) ? 'sku' : 'include_products' );
		}

		if ( 0 === strpos( $field, 'targets.exclude.' ) ) {
			return str_ends_with( $field, '.term' ) ? 'exclude_terms' : 'exclude_products';
		}

		if ( 0 === strpos( $field, 'rules' ) ) {
			return 'rules';
		}

		$map = array(
			'max_discount_minor' => 'max_discount',
			'actions'            => 'type',
			'actions.type'       => 'type',
			'min'                => 'tiers',
			'max'                => 'tiers',
			'per'                => 'tiers',
		);

		if ( isset( $map[ $field ] ) ) {
			return $map[ $field ];
		}

		if ( 'value' === $field && 'tiered' === $type ) {
			return 'tiers';
		}

		return '' === $field ? 'type' : $field;
	}

	/**
	 * The primary action row from the form.
	 *
	 * @param array<string,mixed>  $in
	 * @param array<string,string> $errors
	 * @return array<string,mixed>|null
	 */
	private static function action( string $type, array $in, array &$errors ): ?array {
		$row = array( 'type' => $type );

		switch ( $type ) {
			case 'product_percent':
			case 'cart_percent':
				$pct = self::percent( $in['percent'] ?? '', 'percent', $errors );
				if ( null === $pct ) {
					return null;
				}
				$row['percent'] = $pct;
				return $row;

			case 'product_fixed':
			case 'cart_fixed':
				$amount = self::money( $in['amount'] ?? '', 'amount', $errors );
				if ( null === $amount ) {
					return null;
				}
				$row['amount'] = $amount;
				return $row;

			case 'product_price':
				$price = self::money( $in['price'] ?? '', 'price', $errors );
				if ( null === $price ) {
					return null;
				}
				$row['price'] = $price;
				return $row;

			case 'tiered':
				$tiered = is_array( $in['tiered'] ?? null ) ? $in['tiered'] : array();
				foreach ( array( 'measure' => array( 'quantity', 'subtotal' ), 'mode' => array( 'all_units', 'incremental' ), 'group' => array( 'product', 'pool' ) ) as $key => $allowed ) {
					$value = self::str( $tiered[ $key ] ?? $allowed[0] );
					if ( ! in_array( $value, $allowed, true ) ) {
						$errors['tiers'] = 'unknown_choice';
						return null;
					}
					$row[ $key ] = $value;
				}
				$row['tiers'] = self::tiers( (array) ( $tiered['rows'] ?? array() ), $row['measure'], $errors );
				return isset( $errors['tiers'] ) ? null : $row;

			case 'set_reward':
				$set = is_array( $in['set'] ?? null ) ? $in['set'] : array();
				foreach ( array( 'set_size', 'reward_count' ) as $key ) {
					$raw = self::str( $set[ $key ] ?? '' );
					if ( 1 !== preg_match( '/^\d{1,4}$/', $raw ) ) {
						$errors[ $key ] = 'invalid';
						return null;
					}
					$row[ $key ] = (int) $raw;
				}
				foreach ( array( 'reward' => array( 'free', 'percent', 'fixed' ), 'target' => array( 'cheapest', 'most_expensive' ), 'pool' => array( 'product', 'mixed' ), 'repeat' => array( 'every', 'once' ) ) as $key => $allowed ) {
					$value = self::str( $set[ $key ] ?? $allowed[0] );
					if ( ! in_array( $value, $allowed, true ) ) {
						$errors[ $key ] = 'unknown_choice';
						return null;
					}
					$row[ $key ] = $value;
				}
				if ( 'percent' === $row['reward'] ) {
					$pct = self::percent( $set['value'] ?? '', 'value', $errors );
					if ( null === $pct ) {
						return null;
					}
					$row['value'] = $pct;
				} elseif ( 'fixed' === $row['reward'] ) {
					$amount = self::money( $set['value'] ?? '', 'value', $errors );
					if ( null === $amount ) {
						return null;
					}
					$row['value'] = $amount;
				}
				$reward_products = self::ids( $set['reward_products'] ?? array(), 'reward_products', $errors );
				if ( array() !== $reward_products ) {
					$row['reward_products'] = $reward_products;
				}
				return isset( $errors['reward_products'] ) ? null : $row;

			case 'free_shipping':
				return $row;

			case 'future_reward':
				$future = is_array( $in['future'] ?? null ) ? $in['future'] : array();
				$reward = self::str( $future['reward'] ?? 'fixed' );
				if ( ! in_array( $reward, array( 'fixed', 'percent' ), true ) ) {
					$errors['reward'] = 'unknown_choice';
					return null;
				}
				$row['reward'] = $reward;
				$value         = 'fixed' === $reward ? self::money( $future['value'] ?? '', 'value', $errors ) : self::percent( $future['value'] ?? '', 'value', $errors );
				if ( null === $value ) {
					return null;
				}
				$row['value'] = $value;
				$min          = self::money( $future['min_cart'] ?? '', 'min_cart', $errors, true );
				if ( null !== $min ) {
					$row['min_cart'] = $min;
				}
				$days = self::str( $future['valid_days'] ?? '' );
				if ( '' !== $days ) {
					if ( 1 !== preg_match( '/^\d{1,4}$/', $days ) || 0 === (int) $days ) {
						$errors['valid_days'] = 'invalid';
						return null;
					}
					$row['valid_days'] = (int) $days;
				}
				$cats = self::ids( $future['category_ids'] ?? array(), 'category_ids', $errors );
				if ( array() !== $cats ) {
					$row['category_ids'] = $cats;
				}
				return isset( $errors['min_cart'] ) || isset( $errors['category_ids'] ) ? null : $row;
		}

		return null;
	}

	/**
	 * Form fields of an action row (inverse of action()).
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private static function actionFields( array $row, int $decimals ): array {
		$out   = array();
		$money = static fn( $v ): string => is_numeric( $v ) ? Money::toDecimal( (int) $v, $decimals ) : '';

		switch ( $row['type'] ?? '' ) {
			case 'product_percent':
			case 'cart_percent':
				$out['percent'] = (string) ( $row['percent'] ?? '' );
				break;
			case 'product_fixed':
			case 'cart_fixed':
				$out['amount'] = $money( $row['amount'] ?? null );
				break;
			case 'product_price':
				$out['price'] = $money( $row['price'] ?? null );
				break;
			case 'tiered':
				$measure       = (string) ( $row['measure'] ?? 'quantity' );
				$out['tiered'] = array(
					'measure' => $measure,
					'mode'    => (string) ( $row['mode'] ?? 'all_units' ),
					'group'   => (string) ( $row['group'] ?? 'product' ),
					'rows'    => array(),
				);
				foreach ( (array) ( $row['tiers'] ?? array() ) as $tier ) {
					$type                      = (string) ( $tier['type'] ?? 'percent' );
					$out['tiered']['rows'][]   = array(
						'min'   => 'subtotal' === $measure ? $money( $tier['min'] ?? null ) : (string) ( $tier['min'] ?? '' ),
						'max'   => ! isset( $tier['max'] ) || '' === $tier['max'] ? '' : ( 'subtotal' === $measure ? $money( $tier['max'] ) : (string) $tier['max'] ),
						'type'  => $type,
						'value' => 'fixed' === $type ? $money( $tier['value'] ?? null ) : (string) ( $tier['value'] ?? '' ),
						'per'   => (string) ( $tier['per'] ?? ( 'fixed' === $type ? 'group' : '' ) ),
					);
				}
				break;
			case 'set_reward':
				$reward     = (string) ( $row['reward'] ?? 'free' );
				$out['set'] = array(
					'set_size'        => (string) ( $row['set_size'] ?? '' ),
					'reward_count'    => (string) ( $row['reward_count'] ?? '1' ),
					'reward'          => $reward,
					'value'           => 'fixed' === $reward ? $money( $row['value'] ?? null ) : ( 'percent' === $reward ? (string) ( $row['value'] ?? '' ) : '' ),
					'target'          => (string) ( $row['target'] ?? 'cheapest' ),
					'pool'            => (string) ( $row['pool'] ?? 'product' ),
					'repeat'          => (string) ( $row['repeat'] ?? 'every' ),
					'reward_products' => array_map( 'strval', (array) ( $row['reward_products'] ?? array() ) ),
				);
				break;
			case 'future_reward':
				$reward        = (string) ( $row['reward'] ?? 'fixed' );
				$out['future'] = array(
					'reward'       => $reward,
					'value'        => 'fixed' === $reward ? $money( $row['value'] ?? null ) : (string) ( $row['value'] ?? '' ),
					'min_cart'     => isset( $row['min_cart'] ) ? $money( $row['min_cart'] ) : '',
					'valid_days'   => isset( $row['valid_days'] ) ? (string) $row['valid_days'] : '',
					'category_ids' => array_map( 'strval', (array) ( $row['category_ids'] ?? array() ) ),
				);
				break;
		}

		return $out;
	}

	/**
	 * @param array<int,mixed>     $rows
	 * @param array<string,string> $errors
	 * @return array<int,array<string,mixed>>
	 */
	private static function tiers( array $rows, string $measure, array &$errors ): array {
		$out = array();

		foreach ( $rows as $tier ) {
			if ( ! is_array( $tier ) ) {
				$errors['tiers'] = 'invalid';
				return array();
			}

			$min   = self::str( $tier['min'] ?? '' );
			$value = self::str( $tier['value'] ?? '' );

			if ( '' === $min && '' === $value && '' === self::str( $tier['max'] ?? '' ) ) {
				continue; // An empty row of the form.
			}

			$type = self::str( $tier['type'] ?? 'percent' );

			if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) ) {
				$errors['tiers'] = 'unknown_choice';
				return array();
			}

			$local = array();
			$row   = array(
				'min' => 'subtotal' === $measure ? self::money( $min, 'tiers', $local ) : self::count( $min ),
			);
			$max   = self::str( $tier['max'] ?? '' );

			if ( '' !== $max ) {
				$row['max'] = 'subtotal' === $measure ? self::money( $max, 'tiers', $local ) : self::count( $max );
			}

			$row['type']  = $type;
			$row['value'] = 'fixed' === $type ? self::money( $value, 'tiers', $local ) : self::percent( $value, 'tiers', $local );

			if ( 'fixed' === $type ) {
				$per = self::str( $tier['per'] ?? 'group' );
				$per = '' === $per ? 'group' : $per;

				if ( ! in_array( $per, array( 'group', 'unit' ), true ) ) {
					$local['tiers'] = 'unknown_choice';
				}

				$row['per'] = $per;
			}

			if ( array() !== $local || in_array( null, $row, true ) ) {
				$errors['tiers'] = $local['tiers'] ?? 'invalid';
				return array();
			}

			$out[] = $row;
		}

		if ( array() === $out ) {
			$errors['tiers'] = 'missing';
		}

		return $out;
	}

	private static function count( string $raw ): ?int {
		return 1 === preg_match( '/^\d{1,9}$/', $raw ) && (int) $raw > 0 ? (int) $raw : null;
	}

	/**
	 * Targets from the product/category pickers and the SKU fields.
	 *
	 * @param array<string,mixed>  $in
	 * @param array<string,string> $errors
	 * @return array{include:array<string,array<int,int|string>>,exclude:array<string,array<int,int|string>>}
	 */
	private static function targets( array $in, array &$errors ): array {
		$out = array(
			'include' => array(),
			'exclude' => array(),
		);

		foreach ( array( 'include', 'exclude' ) as $side ) {
			$ids = self::ids( $in[ $side . '_products' ] ?? array(), $side . '_products', $errors );

			foreach ( $ids as $id ) {
				$out[ $side ][ 'product_variation' === get_post_type( $id ) ? 'variation' : 'product' ][] = $id;
			}

			$terms = self::ids( $in[ $side . '_terms' ] ?? array(), $side . '_terms', $errors );

			if ( array() !== $terms ) {
				$out[ $side ]['term'] = $terms;
			}

			$skus = self::skus( $in[ 'include' === $side ? 'sku' : 'exclude_sku' ] ?? '' );

			if ( array() !== $skus ) {
				$out[ $side ]['sku'] = $skus;
			}

			// One key order whatever the input order, so saving twice gives the same definition.
			$sorted = array();

			foreach ( array( 'product', 'variation', 'term', 'sku' ) as $key ) {
				if ( isset( $out[ $side ][ $key ] ) ) {
					$sorted[ $key ] = $out[ $side ][ $key ];
				}
			}

			$out[ $side ] = $sorted;
		}

		return $out;
	}

	/**
	 * A strict id list from an array or a comma/space separated string.
	 *
	 * @param mixed                $raw
	 * @param array<string,string> $errors
	 * @return int[]
	 */
	private static function ids( $raw, string $field, array &$errors ): array {
		$tokens = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', self::str( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$out    = array();

		foreach ( (array) $tokens as $token ) {
			$token = is_int( $token ) ? (string) $token : self::str( $token );

			if ( '' === $token ) {
				continue;
			}

			if ( 1 !== preg_match( '/^\d{1,19}$/', $token ) || 0 === (int) $token ) {
				$errors[ $field ] = 'invalid';

				return array();
			}

			$out[ (int) $token ] = (int) $token;
		}

		return array_values( $out );
	}

	/** @return string[] */
	private static function skus( $raw ): array {
		$out = array();

		foreach ( preg_split( '/\s*,\s*/', self::str( $raw ), -1, PREG_SPLIT_NO_EMPTY ) as $sku ) {
			$sku = substr( sanitize_text_field( $sku ), 0, 100 );

			if ( '' !== $sku && ! in_array( $sku, $out, true ) ) {
				$out[] = $sku;
			}
		}

		return $out;
	}

	/**
	 * The condition tree from the builder's JSON; amounts converted to minor units.
	 *
	 * @param array<string,string> $errors
	 * @return array<string,mixed>|null
	 */
	private static function rules( string $json, array &$errors ): ?array {
		if ( '' === trim( $json ) ) {
			return null;
		}

		$tree = json_decode( $json, true );

		if ( ! is_array( $tree ) ) {
			$errors['rules'] = 'invalid';

			return null;
		}

		$converted = self::convertNode( $tree, 0, $errors );

		if ( isset( $errors['rules'] ) ) {
			return null;
		}

		if ( isset( $converted['children'] ) && array() === $converted['children'] ) {
			return null; // An empty group passes every cart: the same as no conditions.
		}

		return $converted;
	}

	/**
	 * @param array<string,mixed>  $node
	 * @param array<string,string> $errors
	 * @return array<string,mixed>
	 */
	private static function convertNode( array $node, int $depth, array &$errors ): array {
		if ( $depth > RuleTree::MAX_DEPTH ) {
			$errors['rules'] = 'out_of_range';

			return array();
		}

		if ( isset( $node['condition'] ) ) {
			$condition = self::str( $node['condition'] );
			$leaf      = array(
				'condition' => $condition,
				'cmp'       => self::str( $node['cmp'] ?? '' ),
			);

			foreach ( $node as $key => $value ) {
				if ( 'condition' === $key || 'cmp' === $key || 'value' === $key ) {
					continue;
				}

				$leaf[ (string) $key ] = is_array( $value ) ? $value : ( is_scalar( $value ) ? $value : null );
			}

			if ( '' === $leaf['cmp'] ) {
				unset( $leaf['cmp'] );
			}

			if ( array_key_exists( 'value', $node ) ) {
				$leaf['value'] = self::leafValue( $condition, $node['value'], $errors );
			}

			return self::order( $leaf );
		}

		$op = strtolower( self::str( $node['op'] ?? 'and' ) );

		if ( ! in_array( $op, array( 'and', 'or' ), true ) || ( isset( $node['children'] ) && ! is_array( $node['children'] ) ) ) {
			$errors['rules'] = 'invalid';

			return array();
		}

		$children = array();

		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) {
				$errors['rules'] = 'invalid';

				return array();
			}

			$children[] = self::convertNode( $child, $depth + 1, $errors );
		}

		return array(
			'op'       => $op,
			'children' => $children,
		);
	}

	/** Leaf keys in one order: condition, cmp, value, then the rest. */
	private static function order( array $leaf ): array {
		$out = array();

		foreach ( array( 'condition', 'cmp', 'value' ) as $key ) {
			if ( array_key_exists( $key, $leaf ) ) {
				$out[ $key ] = $leaf[ $key ];
			}
		}

		return $out + $leaf;
	}

	/**
	 * @param mixed                $value
	 * @param array<string,string> $errors
	 * @return mixed
	 */
	private static function leafValue( string $condition, $value, array &$errors ) {
		if ( in_array( $condition, self::MONEY_CONDITIONS, true ) ) {
			if ( is_array( $value ) ) {
				$out = array();

				foreach ( $value as $one ) {
					$out[] = self::money( is_scalar( $one ) ? (string) $one : '', 'rules', $errors );
				}

				return $out;
			}

			return self::money( is_scalar( $value ) ? (string) $value : '', 'rules', $errors );
		}

		if ( in_array( $condition, self::INT_CONDITIONS, true ) ) {
			$int = static fn( $v ) => is_string( $v ) && 1 === preg_match( '/^-?\d{1,19}$/', trim( $v ) ) ? (int) trim( $v ) : $v;

			return is_array( $value ) ? array_map( $int, $value ) : $int( $value );
		}

		return $value;
	}

	/**
	 * Builder JSON for a stored tree: amounts back in the store currency.
	 *
	 * @param array<string,mixed> $node
	 * @return array<string,mixed>
	 */
	private static function rulesForForm( array $node, int $decimals ): array {
		if ( isset( $node['condition'] ) ) {
			if ( in_array( $node['condition'], self::MONEY_CONDITIONS, true ) && array_key_exists( 'value', $node ) ) {
				$to            = static fn( $v ) => is_numeric( $v ) ? Money::toDecimal( (int) $v, $decimals ) : $v;
				$node['value'] = is_array( $node['value'] ) ? array_map( $to, $node['value'] ) : $to( $node['value'] );
			}

			return $node;
		}

		$node['children'] = array_map( static fn( $c ) => is_array( $c ) ? self::rulesForForm( $c, $decimals ) : $c, (array) ( $node['children'] ?? array() ) );

		return $node;
	}

	/**
	 * Season and recurrence from the form, in the site's time zone.
	 *
	 * @param array<string,mixed>  $in
	 * @param array<string,string> $errors
	 * @return array<string,mixed>|null
	 */
	private static function schedule( array $in, array &$errors ): ?array {
		$starts = self::localTs( self::str( $in['starts_at'] ?? '' ), 'starts_at', $errors );
		$ends   = self::localTs( self::str( $in['ends_at'] ?? '' ), 'ends_at', $errors );

		if ( null !== $starts && null !== $ends && $ends <= $starts ) {
			$errors['ends_at'] = 'out_of_range';
		}

		$rec  = is_array( $in['recurrence'] ?? null ) ? $in['recurrence'] : array();
		$freq = self::str( $rec['freq'] ?? 'none' );
		$row  = null;

		switch ( $freq ) {
			case '':
			case 'none':
				break;
			case 'daily':
				$row = array(
					'freq'  => 'daily',
					'start' => self::str( $rec['start'] ?? '' ),
					'end'   => self::str( $rec['end'] ?? '' ),
				);
				break;
			case 'weekly':
				$row = array(
					'freq'  => 'weekly',
					'days'  => Recurrence::dayCodes( array_values( (array) ( $rec['days'] ?? array() ) ) ),
					'start' => self::str( $rec['start'] ?? '' ),
					'end'   => self::str( $rec['end'] ?? '' ),
				);
				break;
			case 'weekly_span':
				$row = array(
					'freq'     => 'weekly_span',
					'from_day' => self::str( $rec['from_day'] ?? '' ),
					'from'     => self::str( $rec['from'] ?? '' ),
					'to_day'   => self::str( $rec['to_day'] ?? '' ),
					'to'       => self::str( $rec['to'] ?? '' ),
				);
				break;
			case 'monthly':
				$days   = array();
				$listed = self::str( $rec['month_days'] ?? '' );
				$listed = '' !== $listed ? preg_split( '/[\s,]+/', $listed, -1, PREG_SPLIT_NO_EMPTY ) : (array) ( $rec['days'] ?? array() );
				foreach ( $listed as $day ) {
					$day = self::str( $day );
					if ( 1 === preg_match( '/^\d{1,2}$/', $day ) ) {
						$days[] = (int) $day;
					}
				}
				$row = array(
					'freq'  => 'monthly',
					'days'  => $days,
					'start' => self::str( $rec['start'] ?? '' ),
					'end'   => self::str( $rec['end'] ?? '' ),
				);
				break;
			default:
				$errors['recurrence'] = 'unknown_choice';
		}

		if ( null !== $row && ! Recurrence::fromArray( $row )->isValid() ) {
			$errors['recurrence'] = 'invalid';
		}

		$flash = 'yes' === ( $in['flash'] ?? '' );

		if ( null === $starts && null === $ends && null === $row && ! $flash ) {
			return null;
		}

		return array(
			'starts_ts'  => $starts,
			'ends_ts'    => $ends,
			'recurrence' => $row,
			'timezone'   => wp_timezone_string(),
			'flash'      => $flash,
		);
	}

	/** @param array<string,string> $errors */
	private static function localTs( string $raw, string $field, array &$errors ): ?int {
		if ( '' === $raw ) {
			return null;
		}

		$raw = str_replace( ' ', 'T', $raw );
		$dt  = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw, wp_timezone() );

		if ( false === $dt || $dt->format( 'Y-m-d\TH:i' ) !== $raw ) {
			$errors[ $field ] = 'invalid';

			return null;
		}

		return $dt->getTimestamp();
	}

	private static function localInput( $ts ): string {
		if ( ! is_int( $ts ) && ! ( is_string( $ts ) && ctype_digit( $ts ) ) ) {
			return '';
		}

		return ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	}

	/**
	 * @param array<string,mixed>  $in
	 * @param array<string,string> $errors
	 * @return array<string,mixed>
	 */
	private static function meta( array $in, array &$errors ): array {
		$meta  = array();
		$badge = substr( sanitize_text_field( self::str( $in['badge'] ?? '' ) ), 0, 60 );

		if ( '' !== $badge ) {
			$meta['badge'] = $badge;
		}

		if ( 'yes' === ( $in['public'] ?? '' ) ) {
			$meta['public'] = true;
		}

		$description = sanitize_textarea_field( self::str( $in['description'] ?? '' ) );

		if ( '' !== $description ) {
			$meta['description'] = $description;
		}

		$extra = self::str( $in['extra_meta'] ?? '' );

		if ( '' !== trim( $extra ) ) {
			$decoded = json_decode( $extra, true );

			if ( ! is_array( $decoded ) ) {
				$errors['extra_meta'] = 'invalid';
			} else {
				$meta += array_diff_key( $decoded, array_flip( self::META_FIELDS ) );
			}
		}

		return $meta;
	}

	/**
	 * A JSON list of action rows from a hidden field.
	 *
	 * @param mixed                $raw
	 * @param array<string,string> $errors
	 * @return array<int,array<string,mixed>>
	 */
	private static function jsonList( $raw, string $field, array &$errors ): array {
		$raw = self::str( $raw );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$list = json_decode( $raw, true );

		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			$errors[ $field ] = 'invalid';

			return array();
		}

		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['type'] ?? null ) ) {
				$errors[ $field ] = 'invalid';

				return array();
			}
		}

		return $list;
	}

	/** @param array<string,string> $errors */
	private static function percent( $raw, string $field, array &$errors ): ?string {
		try {
			return Args::percent( self::str( $raw ), $field );
		} catch ( ArgumentError $e ) {
			$errors[ $field ] = $e->reason;

			return null;
		}
	}

	/**
	 * An amount typed in the store currency, as minor units. Accepts "1250", "1250,5", "1250.50",
	 * "1.250,00" and "1,250.00"; anything else is an error.
	 *
	 * @param array<string,string> $errors
	 */
	public static function money( $raw, string $field, array &$errors, bool $optional = false ): ?int {
		$raw = str_replace( array( ' ', "\u{00A0}" ), '', self::str( $raw ) );

		if ( '' === $raw ) {
			if ( ! $optional ) {
				$errors[ $field ] = 'missing';
			}

			return null;
		}

		$d        = self::decimals();
		$frac     = $d > 0 ? '(?:[.,]\d{1,' . $d . '})?' : '';
		$tr_frac  = $d > 0 ? '(?:,\d{1,' . $d . '})?' : '';
		$en_frac  = $d > 0 ? '(?:\.\d{1,' . $d . '})?' : '';
		$plain    = '/^\d{1,15}' . $frac . '$/';
		$tr_group = '/^\d{1,3}(?:\.\d{3})+' . $tr_frac . '$/';
		$en_group = '/^\d{1,3}(?:,\d{3})+' . $en_frac . '$/';

		if ( 1 === preg_match( $plain, $raw ) ) {
			$normal = str_replace( ',', '.', $raw );
		} elseif ( 1 === preg_match( $tr_group, $raw ) ) {
			$normal = str_replace( array( '.', ',' ), array( '', '.' ), $raw );
		} elseif ( 1 === preg_match( $en_group, $raw ) ) {
			$normal = str_replace( ',', '', $raw );
		} else {
			$errors[ $field ] = 'invalid';

			return null;
		}

		return Money::of( $normal, $d );
	}

	private static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/** A trimmed string from a scalar; '' for anything else. */
	private static function str( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
