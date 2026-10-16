<?php
/**
 * A campaign, action or settings value that is present but unusable (or required and missing).
 *
 * Thrown by the strict readers in Args. The evaluator catches it per campaign and records
 * `invalid_args` in the trace with the field and reason, so a broken row never runs as a different
 * campaign and never fails silently; the admin side shows the same field and reason on save.
 *
 * Reasons: missing, invalid, not_integral, too_precise, out_of_range, unknown_choice.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class ArgumentError extends \InvalidArgumentException {

	public function __construct(
		public readonly string $field,
		public readonly string $reason
	) {
		parent::__construct( sprintf( 'Campaign Engine: argument "%s" is %s.', $field, $reason ) );
	}
}
