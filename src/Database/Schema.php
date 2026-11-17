<?php
/**
 * Database schema.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

/**
 * Table names and the dbDelta SQL that creates them.
 */
final class Schema {

	/**
	 * Schema version stored in the wpal_db_version option. Bump it when the SQL changes.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'wpal_db_version';

	/**
	 * Returns the events table name for the current site.
	 *
	 * @return string Table name.
	 */
	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpal_events';
	}

	/**
	 * Returns the changes table name for the current site.
	 *
	 * @return string Table name.
	 */
	public static function changes_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpal_event_changes';
	}

	/**
	 * Returns the CREATE TABLE statements, formatted for dbDelta().
	 *
	 * @param string $prefix  Table prefix of the site, e.g. "wp_" or "wp_2_".
	 * @param string $collate Charset/collation clause from $wpdb->get_charset_collate().
	 *
	 * @return array<int, string> Statements.
	 */
	public static function sql( string $prefix, string $collate ): array {
		return array(
			"CREATE TABLE {$prefix}wpal_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  user_roles varchar(255) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  category varchar(32) NOT NULL DEFAULT '',
  event_type varchar(64) NOT NULL DEFAULT '',
  severity tinyint(3) unsigned NOT NULL DEFAULT 0,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  object_label varchar(255) NOT NULL DEFAULT '',
  message text NOT NULL,
  context longtext NOT NULL,
  session_hash char(16) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_created (user_id,created_at),
  KEY type_created (event_type,created_at),
  KEY category_created (category,created_at),
  KEY object (object_type,object_id),
  KEY ip (ip)
) {$collate};",
			"CREATE TABLE {$prefix}wpal_event_changes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  field varchar(191) NOT NULL DEFAULT '',
  old_value longtext NULL,
  new_value longtext NULL,
  truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) {$collate};",
		);
	}
}
