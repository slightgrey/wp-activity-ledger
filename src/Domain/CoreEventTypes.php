<?php
/**
 * Catalogue of the event types recorded by the core sensors.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * Registers every core event type. Used as a lazy provider of the EventTypeRegistry.
 *
 * Template placeholders: {object}, {object_id}, {object_type}, {type_label}, {user}, {type} and any
 * scalar context key (see EventTypeRegistry::render_message()).
 */
final class CoreEventTypes {

	/**
	 * Registers all core types (provider callback).
	 *
	 * @param EventTypeRegistry $registry Registry.
	 *
	 * @return void
	 */
	public static function register( EventTypeRegistry $registry ): void {
		foreach ( self::definitions() as $definition ) {
			$registry->register( new EventType( ...$definition ) );
		}
	}

	/**
	 * Returns every definition as [key, category, severity, label, template].
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	public static function definitions(): array {
		return array_merge(
			self::auth(),
			self::users(),
			self::content(),
			self::media_and_comments(),
			self::taxonomies_and_menus(),
			self::software(),
			self::settings_and_tools(),
			self::system()
		);
	}

	/**
	 * Authentication types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function auth(): array {
		$c = Category::Auth;

		return array(
			array( 'auth.login', $c, Severity::Info, __( 'Logged in', 'wp-activity-ledger' ), __( '{user} logged in', 'wp-activity-ledger' ) ),
			array( 'auth.logout', $c, Severity::Info, __( 'Logged out', 'wp-activity-ledger' ), __( '{user} logged out', 'wp-activity-ledger' ) ),
			array( 'auth.login_failed', $c, Severity::Warning, __( 'Failed login', 'wp-activity-ledger' ), __( 'Failed login for "{object}" ({count} attempts)', 'wp-activity-ledger' ) ),
			array( 'auth.password_reset_requested', $c, Severity::Notice, __( 'Password reset requested', 'wp-activity-ledger' ), __( 'Password reset requested for "{object}"', 'wp-activity-ledger' ) ),
			array( 'auth.password_reset', $c, Severity::Notice, __( 'Password reset', 'wp-activity-ledger' ), __( 'Password reset completed for "{object}"', 'wp-activity-ledger' ) ),
			array( 'auth.app_password_created', $c, Severity::Notice, __( 'Application password created', 'wp-activity-ledger' ), __( 'Application password "{app_name}" created for "{object}"', 'wp-activity-ledger' ) ),
			array( 'auth.app_password_revoked', $c, Severity::Notice, __( 'Application password revoked', 'wp-activity-ledger' ), __( 'Application password "{app_name}" revoked for "{object}"', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * User management types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function users(): array {
		$c = Category::User;

		return array(
			array( 'user.created', $c, Severity::Notice, __( 'User created', 'wp-activity-ledger' ), __( 'Created user "{object}" with role {role}', 'wp-activity-ledger' ) ),
			array( 'user.updated', $c, Severity::Info, __( 'User updated', 'wp-activity-ledger' ), __( 'Updated profile of "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.deleted', $c, Severity::Warning, __( 'User deleted', 'wp-activity-ledger' ), __( 'Deleted user "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.role_changed', $c, Severity::Notice, __( 'User role changed', 'wp-activity-ledger' ), __( 'Changed role of "{object}" from {old_roles} to {new_roles}', 'wp-activity-ledger' ) ),
			array( 'user.role_added', $c, Severity::Notice, __( 'User role added', 'wp-activity-ledger' ), __( 'Added role {role} to "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.role_removed', $c, Severity::Notice, __( 'User role removed', 'wp-activity-ledger' ), __( 'Removed role {role} from "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.super_admin_granted', $c, Severity::Critical, __( 'Super admin granted', 'wp-activity-ledger' ), __( 'Granted super admin to "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.super_admin_revoked', $c, Severity::Warning, __( 'Super admin revoked', 'wp-activity-ledger' ), __( 'Revoked super admin from "{object}"', 'wp-activity-ledger' ) ),
			array( 'user.session_destroyed', $c, Severity::Notice, __( 'Session ended', 'wp-activity-ledger' ), __( 'Ended {count} session(s) of "{object}"', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Post and page types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function content(): array {
		$c = Category::Content;

		return array(
			array( 'post.created', $c, Severity::Info, __( 'Content created', 'wp-activity-ledger' ), __( 'Created {type_label} "{object}"', 'wp-activity-ledger' ) ),
			array( 'post.updated', $c, Severity::Info, __( 'Content updated', 'wp-activity-ledger' ), __( 'Updated {type_label} "{object}"', 'wp-activity-ledger' ) ),
			array( 'post.published', $c, Severity::Notice, __( 'Content published', 'wp-activity-ledger' ), __( 'Published {type_label} "{object}"', 'wp-activity-ledger' ) ),
			array( 'post.scheduled', $c, Severity::Info, __( 'Content scheduled', 'wp-activity-ledger' ), __( 'Scheduled {type_label} "{object}" for {date}', 'wp-activity-ledger' ) ),
			array( 'post.unpublished', $c, Severity::Notice, __( 'Content unpublished', 'wp-activity-ledger' ), __( 'Unpublished {type_label} "{object}" (now {status})', 'wp-activity-ledger' ) ),
			array( 'post.trashed', $c, Severity::Notice, __( 'Content trashed', 'wp-activity-ledger' ), __( 'Moved {type_label} "{object}" to the trash', 'wp-activity-ledger' ) ),
			array( 'post.restored', $c, Severity::Info, __( 'Content restored', 'wp-activity-ledger' ), __( 'Restored {type_label} "{object}" from the trash', 'wp-activity-ledger' ) ),
			array( 'post.deleted', $c, Severity::Warning, __( 'Content deleted', 'wp-activity-ledger' ), __( 'Permanently deleted {type_label} "{object}"', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Media and comment types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function media_and_comments(): array {
		$m = Category::Media;
		$c = Category::Comment;

		return array(
			array( 'media.uploaded', $m, Severity::Info, __( 'Media uploaded', 'wp-activity-ledger' ), __( 'Uploaded "{object}"', 'wp-activity-ledger' ) ),
			array( 'media.updated', $m, Severity::Info, __( 'Media updated', 'wp-activity-ledger' ), __( 'Updated media "{object}"', 'wp-activity-ledger' ) ),
			array( 'media.edited', $m, Severity::Info, __( 'Image edited', 'wp-activity-ledger' ), __( 'Edited image "{object}"', 'wp-activity-ledger' ) ),
			array( 'media.deleted', $m, Severity::Notice, __( 'Media deleted', 'wp-activity-ledger' ), __( 'Deleted media "{object}"', 'wp-activity-ledger' ) ),
			array( 'comment.approved', $c, Severity::Info, __( 'Comment approved', 'wp-activity-ledger' ), __( 'Approved comment by {author} on "{object}"', 'wp-activity-ledger' ) ),
			array( 'comment.unapproved', $c, Severity::Info, __( 'Comment unapproved', 'wp-activity-ledger' ), __( 'Unapproved comment by {author} on "{object}"', 'wp-activity-ledger' ) ),
			array( 'comment.spammed', $c, Severity::Info, __( 'Comment marked as spam', 'wp-activity-ledger' ), __( 'Marked comment by {author} on "{object}" as spam', 'wp-activity-ledger' ) ),
			array( 'comment.trashed', $c, Severity::Info, __( 'Comment trashed', 'wp-activity-ledger' ), __( 'Trashed comment by {author} on "{object}"', 'wp-activity-ledger' ) ),
			array( 'comment.deleted', $c, Severity::Notice, __( 'Comment deleted', 'wp-activity-ledger' ), __( 'Deleted comment by {author} on "{object}"', 'wp-activity-ledger' ) ),
			array( 'comment.edited', $c, Severity::Info, __( 'Comment edited', 'wp-activity-ledger' ), __( 'Edited comment by {author} on "{object}"', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Taxonomy, menu, widget and Customizer types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function taxonomies_and_menus(): array {
		$t = Category::Taxonomy;
		$m = Category::Menu;
		$w = Category::Widget;

		return array(
			array( 'term.created', $t, Severity::Info, __( 'Term created', 'wp-activity-ledger' ), __( 'Created {taxonomy} "{object}"', 'wp-activity-ledger' ) ),
			array( 'term.updated', $t, Severity::Info, __( 'Term updated', 'wp-activity-ledger' ), __( 'Updated {taxonomy} "{object}"', 'wp-activity-ledger' ) ),
			array( 'term.deleted', $t, Severity::Notice, __( 'Term deleted', 'wp-activity-ledger' ), __( 'Deleted {taxonomy} "{object}"', 'wp-activity-ledger' ) ),
			array( 'menu.created', $m, Severity::Info, __( 'Menu created', 'wp-activity-ledger' ), __( 'Created menu "{object}"', 'wp-activity-ledger' ) ),
			array( 'menu.updated', $m, Severity::Info, __( 'Menu updated', 'wp-activity-ledger' ), __( 'Updated menu "{object}"', 'wp-activity-ledger' ) ),
			array( 'menu.deleted', $m, Severity::Notice, __( 'Menu deleted', 'wp-activity-ledger' ), __( 'Deleted menu "{object}"', 'wp-activity-ledger' ) ),
			array( 'menu.locations_changed', $m, Severity::Info, __( 'Menu locations changed', 'wp-activity-ledger' ), __( 'Changed menu locations', 'wp-activity-ledger' ) ),
			array( 'widget.updated', $w, Severity::Info, __( 'Widgets updated', 'wp-activity-ledger' ), __( 'Updated widgets', 'wp-activity-ledger' ) ),
			array( 'customizer.saved', $w, Severity::Notice, __( 'Customizer saved', 'wp-activity-ledger' ), __( 'Saved {count} Customizer setting(s)', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Plugin, theme and core software types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function software(): array {
		$p = Category::Plugin;
		$t = Category::Theme;
		$c = Category::Core;

		return array(
			array( 'plugin.installed', $p, Severity::Warning, __( 'Plugin installed', 'wp-activity-ledger' ), __( 'Installed plugin "{object}" {version}', 'wp-activity-ledger' ) ),
			array( 'plugin.activated', $p, Severity::Warning, __( 'Plugin activated', 'wp-activity-ledger' ), __( 'Activated plugin "{object}"', 'wp-activity-ledger' ) ),
			array( 'plugin.deactivated', $p, Severity::Warning, __( 'Plugin deactivated', 'wp-activity-ledger' ), __( 'Deactivated plugin "{object}"', 'wp-activity-ledger' ) ),
			array( 'plugin.updated', $p, Severity::Notice, __( 'Plugin updated', 'wp-activity-ledger' ), __( 'Updated plugin "{object}" from {old_version} to {new_version}', 'wp-activity-ledger' ) ),
			array( 'plugin.deleted', $p, Severity::Warning, __( 'Plugin deleted', 'wp-activity-ledger' ), __( 'Deleted plugin "{object}" {version}', 'wp-activity-ledger' ) ),
			array( 'plugin.file_edited', $p, Severity::Critical, __( 'Plugin file edited', 'wp-activity-ledger' ), __( 'Edited plugin file {file}', 'wp-activity-ledger' ) ),
			array( 'plugin.auto_update_changed', $p, Severity::Notice, __( 'Plugin auto-updates changed', 'wp-activity-ledger' ), __( 'Turned auto-updates {state} for plugin "{object}"', 'wp-activity-ledger' ) ),
			array( 'theme.installed', $t, Severity::Warning, __( 'Theme installed', 'wp-activity-ledger' ), __( 'Installed theme "{object}" {version}', 'wp-activity-ledger' ) ),
			array( 'theme.switched', $t, Severity::Warning, __( 'Theme switched', 'wp-activity-ledger' ), __( 'Switched theme from "{old_theme}" to "{object}"', 'wp-activity-ledger' ) ),
			array( 'theme.updated', $t, Severity::Notice, __( 'Theme updated', 'wp-activity-ledger' ), __( 'Updated theme "{object}" from {old_version} to {new_version}', 'wp-activity-ledger' ) ),
			array( 'theme.deleted', $t, Severity::Warning, __( 'Theme deleted', 'wp-activity-ledger' ), __( 'Deleted theme "{object}"', 'wp-activity-ledger' ) ),
			array( 'theme.file_edited', $t, Severity::Critical, __( 'Theme file edited', 'wp-activity-ledger' ), __( 'Edited theme file {file}', 'wp-activity-ledger' ) ),
			array( 'theme.auto_update_changed', $t, Severity::Notice, __( 'Theme auto-updates changed', 'wp-activity-ledger' ), __( 'Turned auto-updates {state} for theme "{object}"', 'wp-activity-ledger' ) ),
			array( 'core.updated', $c, Severity::Notice, __( 'WordPress updated', 'wp-activity-ledger' ), __( 'Updated WordPress from {old_version} to {new_version}', 'wp-activity-ledger' ) ),
			array( 'core.auto_update_changed', $c, Severity::Notice, __( 'Core auto-updates changed', 'wp-activity-ledger' ), __( 'Changed WordPress core auto-update settings', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Settings and Tools types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function settings_and_tools(): array {
		$c = Category::Core;

		return array(
			array( 'option.updated', Category::Settings, Severity::Notice, __( 'Settings changed', 'wp-activity-ledger' ), __( 'Changed {count} setting(s) on {object}', 'wp-activity-ledger' ) ),
			array( 'core.export_run', $c, Severity::Notice, __( 'Content exported', 'wp-activity-ledger' ), __( 'Exported site content ({content})', 'wp-activity-ledger' ) ),
			array( 'core.import_run', $c, Severity::Notice, __( 'Content imported', 'wp-activity-ledger' ), __( 'Imported content', 'wp-activity-ledger' ) ),
			array( 'core.privacy_export', $c, Severity::Notice, __( 'Personal data exported', 'wp-activity-ledger' ), __( 'Exported personal data for {object}', 'wp-activity-ledger' ) ),
			array( 'core.privacy_erase', $c, Severity::Warning, __( 'Personal data erased', 'wp-activity-ledger' ), __( 'Erased personal data for {object}', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Activity Ledger's own types.
	 *
	 * @return array<int, array{0: string, 1: Category, 2: Severity, 3: string, 4: string}> Definitions.
	 */
	private static function system(): array {
		$c = Category::System;

		return array(
			array( 'system.activated', $c, Severity::Notice, __( 'Activity Ledger activated', 'wp-activity-ledger' ), __( 'Activity Ledger was activated', 'wp-activity-ledger' ) ),
			array( 'system.deactivated', $c, Severity::Critical, __( 'Activity Ledger deactivated', 'wp-activity-ledger' ), __( 'Activity Ledger was deactivated', 'wp-activity-ledger' ) ),
			array( 'system.settings_changed', $c, Severity::Warning, __( 'Ledger settings changed', 'wp-activity-ledger' ), __( 'Changed Activity Ledger settings', 'wp-activity-ledger' ) ),
			array( 'system.alert_rules_changed', $c, Severity::Notice, __( 'Alert rules changed', 'wp-activity-ledger' ), __( 'Changed alert rule "{object}"', 'wp-activity-ledger' ) ),
			array( 'system.alert_channels_changed', $c, Severity::Notice, __( 'Alert channels changed', 'wp-activity-ledger' ), __( 'Changed alert channel "{object}"', 'wp-activity-ledger' ) ),
			array( 'system.alert_failed', $c, Severity::Warning, __( 'Alert delivery failed', 'wp-activity-ledger' ), __( 'Alert to channel "{object}" failed: {error}', 'wp-activity-ledger' ) ),
			array( 'system.logs_exported', $c, Severity::Notice, __( 'Logs exported', 'wp-activity-ledger' ), __( 'Exported {count} log entries as {format}', 'wp-activity-ledger' ) ),
			array( 'system.logs_purged', $c, Severity::Critical, __( 'Logs purged', 'wp-activity-ledger' ), __( 'Purged {count} log entries', 'wp-activity-ledger' ) ),
			array( 'system.pruned', $c, Severity::Info, __( 'Old logs pruned', 'wp-activity-ledger' ), __( 'Retention removed {count} old log entries', 'wp-activity-ledger' ) ),
		);
	}
}
