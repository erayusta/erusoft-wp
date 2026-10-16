<?php
/**
 * Which lines a campaign may touch: include and exclude lists of products, variations, terms and SKUs.
 *
 * An empty include list means every product. Exclusion always wins, and lines flagged `excluded` by
 * the store (gift cards, fees…) never match any campaign.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Campaign;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Context\Item;

final class Targets {

	public const KEYS = array( 'product', 'variation', 'term', 'sku' );

	/** @var array<string,array<string|int,bool>> */
	private array $include;

	/** @var array<string,array<string|int,bool>> */
	private array $exclude;

	/**
	 * @param array<string,mixed> $include e.g. ['product' => [12], 'term' => [61]]
	 * @param array<string,mixed> $exclude
	 */
	public function __construct( array $include = array(), array $exclude = array() ) {
		$this->include = self::normalise( $include, 'include' );
		$this->exclude = self::normalise( $exclude, 'exclude' );
	}

	/** Every product. */
	public static function all(): self {
		return new self();
	}

	/**
	 * @param array<string,mixed> $row ['include' => [...], 'exclude' => [...]]
	 */
	public static function fromArray( array $row ): self {
		return new self( (array) ( $row['include'] ?? array() ), (array) ( $row['exclude'] ?? array() ) );
	}

	/** @return array{include:array<string,array<int,int|string>>,exclude:array<string,array<int,int|string>>} */
	public function toArray(): array {
		$out = array();

		foreach ( array( 'include' => $this->include, 'exclude' => $this->exclude ) as $side => $set ) {
			foreach ( self::KEYS as $key ) {
				$out[ $side ][ $key ] = array_map( static fn( $v ) => 'sku' === $key ? (string) $v : (int) $v, array_keys( $set[ $key ] ) );
			}
		}

		return $out;
	}

	/** True when the include list is empty (the campaign is storewide unless excluded). */
	public function matchesEverything(): bool {
		foreach ( self::KEYS as $key ) {
			if ( array() !== $this->include[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	public function matches( Item $item ): bool {
		if ( $item->excluded || $this->hits( $this->exclude, $item ) ) {
			return false;
		}

		return $this->matchesEverything() || $this->hits( $this->include, $item );
	}

	/**
	 * @param array<string,array<string|int,bool>> $set
	 */
	private function hits( array $set, Item $item ): bool {
		if ( isset( $set['product'][ $item->product_id ] ) ) {
			return true;
		}

		if ( 0 !== $item->variation_id && isset( $set['variation'][ $item->variation_id ] ) ) {
			return true;
		}

		if ( '' !== $item->sku && isset( $set['sku'][ strtoupper( $item->sku ) ] ) ) {
			return true;
		}

		foreach ( $item->termIds() as $term_id ) {
			if ( isset( $set['term'][ $term_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Malformed ids throw ArgumentError (field "targets.<side>.<key>"): dropping one could empty the
	 * include list and make the campaign storewide.
	 *
	 * @param array<string,mixed> $set
	 * @return array<string,array<string|int,bool>>
	 * @throws ArgumentError
	 */
	private static function normalise( array $set, string $side ): array {
		$out = array();

		foreach ( self::KEYS as $key ) {
			if ( 'sku' === $key ) {
				$out[ $key ] = array_fill_keys( array_map( 'strtoupper', Args::strings( $set[ $key ] ?? array() ) ), true );
				continue;
			}

			$out[ $key ] = array_fill_keys( Args::idList( $set[ $key ] ?? array(), 'targets.' . $side . '.' . $key ), true );
		}

		return $out;
	}
}
