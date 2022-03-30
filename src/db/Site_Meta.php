<?php

namespace EE\Model;
use EE;

/**
 * Site model class.
 */
class Site_Meta extends Base {

	/**
	 * @var string Table of the model from where it will be stored/retrived
	 */
	protected static $table = 'site_meta';

	/**
	 * Create meta data wrapper.
	 *
	 * @param int $site_id Site id for which the meta is refer.
	 * @param string $meta_key Meta key for site.
	 * @param string $meta_value Meta value for site.
	 *
	 * @return bool|int Return meta id for successful insert and false for failed.
	 * @throws \Exception
	 */
	public static function set( $site_id, $meta_key, $meta_value ) {

		$site_meta = array(
			'site_id'    => $site_id,
			'meta_key'   => $meta_key,
			'meta_Value' => $meta_value
		);

		return self::create( $site_meta );
	}

	/**
	 * Find meta ids for related meta key.
	 *
	 * @param string $meta_key Meta key for which data needs to be serch.
	 *
	 * @return array|bool array of meta ids.
	 * @throws \Exception
	 */
	public static function find_ids_from_key( $meta_key ) {

		$site_meta = array(
			'meta_key' => $meta_key,
		);

		$meta = EE::db()
			->table( static::$table )
			->select( 'id' )
			->where( $site_meta )
			->all();

		if ( $meta ) {
			$ids = array_column( $meta, 'id' );

			return $ids ?? false;
		}

		return false;
	}

	/**
	 * Get meta value from site id and meta key.
	 *
	 * @param int $site_id Site id for which the meta is refer.
	 * @param string $meta_key Meta key for site.
	 *
	 * @return bool|string Meta value of search meta or false for failed search.
	 * @throws \Exception
	 */
	public static function get( $site_id, $meta_key ) {

		$site_meta = array(
			'site_id'    => $site_id,
			'meta_key'   => $meta_key,
		);

		$meta = EE::db()
			->table( static::$table )
			->select( 'meta_value' )
			->where( $site_meta )
			->first();

		if ( $meta && $meta['meta_value'] ) {
			return $meta['meta_value'];
		}

		return false;
	}
}
