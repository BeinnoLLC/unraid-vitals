/* unraid-vitals — scoring for the findings eval (#117 / P20-12).
 *
 * Kept out of scripts/agent-eval.mjs so it can be unit-tested: that script
 * runs the whole eval at import time, so anything inline in it is only ever
 * exercised by a real model run — which is how an errored run managed to
 * print "recall=100%" for months.
 *
 * The bug this exists to prevent: a run that errors produces no findings, so
 * `detected + missed === 0` and `okOnly` is still its initial `true`. Scored
 * naively that is recall = 1 = 100%: a perfect score for a run where the model
 * never answered. An error is not evidence of correctness.
 */

/** Recall for one fixture run, or null when the run errored (score is unknown, not perfect). */
export function recallOf(R = {}) {
  if (R.error) return null; // no answer != a correct answer
  const total = (R.detected || 0) + (R.missed || 0);
  if (total === 0) return R.okOnly ? 1 : 0; // only ok/info findings, nothing expected to fire
  return (R.detected || 0) / total;
}

/** Share of runs that produced a finding nothing asked for. */
export function falseRateOf(R = {}) {
  return R.runs ? (R.false || 0) / R.runs : 0;
}

/** Human-readable score for the eval report. `n/a` is not `100%`. */
export function scoreLine(R = {}) {
  const recall = recallOf(R);
  return {
    recall,
    recallStr: recall === null ? 'n/a' : `${(recall * 100).toFixed(0)}%`,
    falseRate: falseRateOf(R),
  };
}
