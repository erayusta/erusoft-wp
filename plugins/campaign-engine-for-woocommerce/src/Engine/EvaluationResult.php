<?php
/**
 * What `Evaluator::evaluate()` returns: the resolved adjustments, the trace, and the candidates as
 * they were before resolution (for previews that want to show "this campaign would give X").
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

use Erusoft\CampaignEngine\Engine\Adjustment\AdjustmentSet;

final class EvaluationResult {

	/**
	 * @param Candidate[] $candidates
	 */
	public function __construct(
		public readonly AdjustmentSet $adjustments,
		public readonly Trace $trace,
		private readonly array $candidates = array()
	) {}

	/** @return int[] Campaigns that ended up with at least one adjustment. */
	public function appliedCampaignIds(): array {
		return $this->adjustments->campaignIds();
	}

	/** @return Candidate[] */
	public function evaluatedCandidates(): array {
		return $this->candidates;
	}
}
