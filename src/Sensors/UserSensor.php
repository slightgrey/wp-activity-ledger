<?php
/**
 * User management sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\Severity;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records user creation, deletion, role changes and super admin grants.
 *
 * Creating an administrator or promoting someone to administrator is critical.
 */
final class UserSensor implements SensorInterface {

	/**
	 * Role additions/removals waiting to be recorded: user ID => list of [type, role].
	 *
	 * WP_User::set_role() fires add_user_role/remove_user_role and then set_user_role,
	 * so deltas are held until we know whether they belong to a set_role() call.
	 *
	 * @var array<int, array<int, array{0: string, 1: string}>>
	 */
	private array $role_deltas = array();

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 * @param Differ   $differ   Differ.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'user_register', array( $this, 'on_register' ), 10, 1 );
		$loader->add_action( 'delete_user', array( $this, 'on_delete' ), 10, 2 );
		$loader->add_action( 'set_user_role', array( $this, 'on_role_set' ), 10, 3 );
		$loader->add_action( 'add_user_role', array( $this, 'on_role_added' ), 10, 2 );
		$loader->add_action( 'remove_user_role', array( $this, 'on_role_removed' ), 10, 2 );
		$loader->add_action( 'granted_super_admin', array( $this, 'on_super_admin_granted' ), 10, 1 );
		$loader->add_action( 'revoked_super_admin', array( $this, 'on_super_admin_revoked' ), 10, 1 );
		$loader->add_action( 'shutdown', array( $this, 'record_role_deltas' ), 0, 0 );
	}

	/**
	 * Records role additions/removals that were not part of a set_role() call.
	 *
	 * Runs on shutdown before the Recorder flushes (priority 1); also callable directly.
	 *
	 * @return void
	 */
	public function record_role_deltas(): void {
		$deltas            = $this->role_deltas;
		$this->role_deltas = array();

		foreach ( $deltas as $user_id => $list ) {
			foreach ( $list as [ $type, $role ] ) {
				$this->record_role_delta( $type, $user_id, $role );
			}
		}
	}

	/**
	 * Records a new user; critical when they are an administrator.
	 *
	 * @param int $user_id New user.
	 *
	 * @return void
	 */
	public function on_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );

		if ( false === $user ) {
			return;
		}

		$roles = array_values( $user->roles );

		$this->record(
			'user.created',
			$user,
			array(
				'role'  => implode( ', ', $roles ),
				'email' => (string) $user->user_email,
			),
			array(),
			in_array( 'administrator', $roles, true ) ? Severity::Critical : null
		);
	}

	/**
	 * Records a user deletion, before the account is gone.
	 *
	 * @param int      $user_id  Deleted user.
	 * @param int|null $reassign User who receives the content, if any.
	 *
	 * @return void
	 */
	public function on_delete( $user_id, $reassign = null ): void {
		$user = get_userdata( (int) $user_id );

		if ( false === $user ) {
			return;
		}

		$target = null === $reassign ? false : get_userdata( (int) $reassign );

		$this->record(
			'user.deleted',
			$user,
			array(
				'roles'       => implode( ', ', $user->roles ),
				'email'       => (string) $user->user_email,
				'reassign_to' => false === $target ? '' : (string) $target->user_login,
			)
		);
	}

	/**
	 * Records a role change (set_role). Initial roles of new users are skipped.
	 *
	 * @param int                $user_id   User.
	 * @param string             $role      New role.
	 * @param array<int, string> $old_roles Previous roles.
	 *
	 * @return void
	 */
	public function on_role_set( $user_id, $role, $old_roles = array() ): void {
		unset( $this->role_deltas[ (int) $user_id ] ); // They were part of this set_role() call.

		$old_roles = array_values( (array) $old_roles );
		$user      = get_userdata( (int) $user_id );

		if ( array() === $old_roles || false === $user || array( (string) $role ) === $old_roles ) {
			return;
		}

		$this->record(
			'user.role_changed',
			$user,
			array(
				'old_roles' => implode( ', ', $old_roles ),
				'new_roles' => (string) $role,
			),
			$this->role_changes( $old_roles, array( (string) $role ) ),
			'administrator' === $role ? Severity::Critical : null
		);
	}

	/**
	 * Records an extra role being added.
	 *
	 * @param int    $user_id User.
	 * @param string $role    Role.
	 *
	 * @return void
	 */
	public function on_role_added( $user_id, $role ): void {
		$this->role_deltas[ (int) $user_id ][] = array( 'user.role_added', (string) $role );
	}

	/**
	 * Records a role being removed.
	 *
	 * @param int    $user_id User.
	 * @param string $role    Role.
	 *
	 * @return void
	 */
	public function on_role_removed( $user_id, $role ): void {
		$this->role_deltas[ (int) $user_id ][] = array( 'user.role_removed', (string) $role );
	}

	/**
	 * Records a super admin grant.
	 *
	 * @param int $user_id User.
	 *
	 * @return void
	 */
	public function on_super_admin_granted( $user_id ): void {
		$user = get_userdata( (int) $user_id );

		if ( false !== $user ) {
			$this->record( 'user.super_admin_granted', $user );
		}
	}

	/**
	 * Records a super admin revocation.
	 *
	 * @param int $user_id User.
	 *
	 * @return void
	 */
	public function on_super_admin_revoked( $user_id ): void {
		$user = get_userdata( (int) $user_id );

		if ( false !== $user ) {
			$this->record( 'user.super_admin_revoked', $user );
		}
	}

	/**
	 * Records an added or removed role; adding administrator is critical.
	 *
	 * @param string $type    Event type.
	 * @param int    $user_id User.
	 * @param string $role    Role.
	 *
	 * @return void
	 */
	private function record_role_delta( string $type, int $user_id, string $role ): void {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return;
		}

		$critical = 'user.role_added' === $type && 'administrator' === $role;

		$this->record( $type, $user, array( 'role' => $role ), array(), $critical ? Severity::Critical : null );
	}

	/**
	 * Returns the role change as a Change list.
	 *
	 * @param array<int, string> $old_roles Old roles.
	 * @param array<int, string> $new_roles New roles.
	 *
	 * @return array<int, Change> Changes.
	 */
	private function role_changes( array $old_roles, array $new_roles ): array {
		$change = $this->differ->compare( 'roles', $old_roles, $new_roles );

		return null === $change ? array() : array( $change );
	}

	/**
	 * Records an event about a user account.
	 *
	 * @param string               $type     Event type.
	 * @param \WP_User             $user     Account.
	 * @param array<string, mixed> $context  Context.
	 * @param array<int, Change>   $changes  Changes.
	 * @param Severity|null        $severity Severity override.
	 *
	 * @return void
	 */
	private function record( string $type, \WP_User $user, array $context = array(), array $changes = array(), ?Severity $severity = null ): void {
		$this->recorder->record(
			new Event(
				$type,
				severity: $severity?->value,
				object_type: 'user',
				object_id: (string) $user->ID,
				object_label: (string) $user->user_login,
				context: $context,
				changes: $changes
			)
		);
	}
}
