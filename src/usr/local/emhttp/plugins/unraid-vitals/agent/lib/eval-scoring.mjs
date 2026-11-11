

export function recallOf(R = {}) {
  if (R.error) return null; 
  const total = (R.detected || 0) + (R.missed || 0);
  if (total === 0) return R.okOnly ? 1 : 0; 
  return (R.detected || 0) / total;
}

export function falseRateOf(R = {}) {
  return R.runs ? (R.false || 0) / R.runs : 0;
}

export function scoreLine(R = {}) {
  const recall = recallOf(R);
  return {
    recall,
    recallStr: recall === null ? 'n/a' : `${(recall * 100).toFixed(0)}%`,
    falseRate: falseRateOf(R),
  };
}
