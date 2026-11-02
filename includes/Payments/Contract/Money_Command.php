<?php
/**
 * One money command as the till sent it.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WP_Error;
use WP_REST_Request;

/**
 * What `Command_Log::run()` answers (ADR 0040 rules 5 and 6): the till-minted id, the fingerprint of the
 * body, the kind, what it acts on (a row id, a method id, or '' for record and close), the refund or
 * attempt it names, and the order revision it was built against. Immutable.
 */
final class Money_Command {
	/** The kinds whose body may name the register that completes the sale; the rest complete nothing at a register. */
	private const PROVENANCE_KINDS = array( 'record', 'intent', 'capture', 'submit', 'close' );

	/**
	 * The command's facts, read through the getters.
	 *
	 * @var array{id: string, fp: string, kind: string, target: string, ref: int|string|null, revision: string}
	 */
	private $facts;

	/**
	 * Where a completion this command makes happened.
	 *
	 * @var Completion_Origin
	 */
	private $origin;

	/**
	 * Hold one command.
	 *
	 * @param string            $id       Command id.
	 * @param string            $fp       Body fingerprint.
	 * @param string            $kind     Command kind.
	 * @param string            $target   Row id, method id or ''.
	 * @param int|string|null   $ref      Refund id or attempt id.
	 * @param string            $revision Order revision.
	 * @param Completion_Origin $origin   Where a completion it makes happened; the storage chain when null.
	 */
	public function __construct( string $id, string $fp, string $kind, string $target = '', $ref = null, string $revision = '', ?Completion_Origin $origin = null ) {
		$id           = strtolower( $id );
		$target       = strtolower( $target );
		$this->facts  = compact( 'id', 'fp', 'kind', 'target', 'ref', 'revision' );
		$this->origin = $origin ?? new Completion_Origin();
	}

	/**
	 * A route's command: `record` is keyed by its row id (it creates the row), every other kind by its
	 * `command_id`, and every one carries the revision. A `record` with no row id is null: its handler
	 * answers the 400.
	 *
	 * @param string          $kind    Command kind.
	 * @param WP_REST_Request $request Request.
	 * @return self|null|WP_Error 400 `rest_invalid_param` for a bad command id, 428 `wcpos_revision_required`.
	 */
	public static function parse( string $kind, WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$params = is_array( $params ) ? $params : array();
		$record = 'record' === $kind;
		$id     = $record ? ( $params['payment']['id'] ?? null ) : ( $params['command_id'] ?? null );
		if ( ! $record && ( ! is_string( $id ) || ! wp_is_uuid( strtolower( $id ) ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Command ID must be a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$revision = $params['revision'] ?? null;
		if ( ! is_string( $revision ) || '' === $revision ) {
			return new WP_Error( 'wcpos_revision_required', __( 'A payment command needs the order revision it was built against.', 'woocommerce-pos' ), array( 'status' => 428 ) );
		}
		if ( ! is_string( $id ) ) {
			return null;
		}
		$target = $record ? '' : (string) $request[ in_array( $kind, Command_Log::ROW_KINDS, true ) ? 'uuid' : 'method' ];
		$ref    = null;
		if ( 'refund' === $kind && is_numeric( $params['refund_id'] ?? null ) ) {
			$ref = (int) $params['refund_id'];
		} elseif ( in_array( $kind, array( 'submit', 'cancel' ), true ) && is_string( $params['attempt_id'] ?? null ) ) {
			$ref = strtolower( $params['attempt_id'] );
		}
		// The body's provenance is fingerprinted, so a same-id replay completes at the same origin.
		$provenance = in_array( $kind, self::PROVENANCE_KINDS, true ) && is_array( $params['provenance'] ?? null ) ? $params['provenance'] : array();
		return new self( $id, Command_Log::fingerprint( $kind, $target, $params ), $kind, $target, $ref, $revision, new Completion_Origin( $provenance, false, get_current_user_id() ) );
	}

	/** The command id. */
	public function id(): string {
		return $this->facts['id'];
	}

	/** The body fingerprint. */
	public function fp(): string {
		return $this->facts['fp'];
	}

	/** The command kind. */
	public function kind(): string {
		return $this->facts['kind'];
	}

	/** The row id (row kinds), the method id (submit, cancel), or ''. */
	public function target(): string {
		return $this->facts['target'];
	}

	/**
	 * The refund id (refund) or attempt id (submit, cancel); null otherwise.
	 *
	 * @return int|string|null
	 */
	public function ref() {
		return $this->facts['ref'];
	}

	/** The order revision the command was built against. */
	public function revision(): string {
		return $this->facts['revision'];
	}

	/** Where a completion this command makes happened: the body's provenance and the cashier sending it. */
	public function origin(): Completion_Origin {
		return $this->origin;
	}
}
