<?php
/**
 * Prompt planner for the ask-once research box.
 *
 * The UI is ONE free-text field and ONE button ("Research"). These pure
 * functions detect from the prompt alone:
 *   - study intent ("monitor", "watch", "for the next 12 hours", …) vs a
 *     one-shot question,
 *   - the study window (1–72 h) and tick cadence (5–180 min),
 *   - a rough completion ETA (one-shot: LLM latency estimate; study:
 *     first-tick + full-window figures).
 *
 * Deliberately keyword/regex based and deterministic: it must behave the
 * same on every box, cost zero LLM calls, and never delay the submit.
 * The research agent itself gets the raw prompt either way — this only
 * decides HOW the job runs.
 */

/** "30" + "minutes" -> 1 (clamped to the 1..72 h product range). */
function v_plan_hours_from(int $n, string $unit): int {
  $h = $n;
  if ($unit === 'm') $h = $n / 60;
  elseif ($unit === 'd') $h = $n * 24;
  elseif ($unit === 'w') $h = $n * 168;
  return (int)round(max(1, min(72, $h)));
}

function v_plan_detect_study(string $p): array {
  // php-mbstring is NOT a dependency anywhere else in this plugin; plain
  // strtolower is enough for keyword matching (ASCII keywords only).
  $p = strtolower($p);
  // Duration: NUMBER+UNIT matched as ONE anchored token, alternation
  // listing multi-char units first, so "30 minutes" can never be read as
  // "30 m"-as-hours and "2 days" can never satisfy an hours-only branch.
  $hours = null;
  if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*(minutes?|mins?|hours?|hrs?|days?|weeks?|m|h|d|w)\b/', $p, $m)) {
    $u = $m[2];
    if ($u[0] === 'm' && !(strlen($u) > 1 && $u[1] === 'i')) $u = 'm'; // bare "m" = minutes
    else $u = $u[0];
    $hours = v_plan_hours_from((int)$m[1], $u);
  }
  // Duration words with no number: "overnight", "all day", "this week".
  // These only imply FUTURE watching when a study verb is present —
  // "why is the cache hot this week?" is a question ABOUT the past week,
  // not a request to watch the next one.
  $verbs = '/\b(monitor|watch|observe|track|keep an eye|study|keep watching|look out for)\b/';
  $hasVerb = (bool)preg_match($verbs, $p);
  if ($hours === null && $hasVerb) {
    if (preg_match('/\bovernight\b|\btonight\b/', $p)) $hours = 12;
    elseif (preg_match('/\ball day\b/', $p)) $hours = 24;
    elseif (preg_match('/\bthis weekend\b/', $p)) $hours = 48;
    elseif (preg_match('/\bthis week\b|\bfor a week\b/', $p)) $hours = 72;
  }
  // A NUMBER duration is retrospective when anchored by "ago" or "the
  // last" — "why did disk3 fail 2 days ago" is history, not a plan.
  // In that case the duration only counts when a study verb is present.
  if ($hours !== null && !$hasVerb
      && (preg_match('/\bago\b/', $p) || preg_match('/\blast\s+[0-9]/', $p))) {
    $hours = null;
  }
  // "Spikes" etc. ARE temporal intent even without "for the next N hours":
  // "monitor CPU spikes" means "catch the next one whenever it happens".
  $temporal = '/\b(spikes?|over time|for the next|next \d|while i|until|when it happens|as it happens|trends?|abnormal|anomal)\b/';
  $isStudy = $hours !== null
    || (bool)preg_match('/\bstudy\b/', $p)
    || ($hasVerb && (bool)preg_match($temporal, $p));
  if (!$isStudy) return ['study' => false, 'hours' => null, 'tick' => null];
  $h = $hours ?? 12;
  // Tick cadence: fast phenomena want fast ticks.
  if (preg_match('/\b(spike|spikes|burst|transient|thermal|temperature|temps|temp|fan|fans)\b/', $p)) $tick = 5;
  elseif (preg_match('/\b(capacit|growth|trend|drift|week|weeks|days)\b/', $p)) $tick = 60;
  else $tick = $h <= 6 ? 10 : 15;
  return ['study' => true, 'hours' => $h, 'tick' => $tick];
}

/**
 * Plan a research job from a free-text prompt.
 * @return array{study:bool,hours:int,tick:int,eta_seconds:int,eta_label:string}
 */
function v_plan_research(string $prompt): array {
  $s = v_plan_detect_study($prompt);
  $eta = 0;
  $label = '';
  if ($s['study']) {
    $dur = $s['hours'] * 3600;
    // First report lands after the first tick + one LLM synthesis pass
    // (measured 90–300 s per pass on the local studio); the final one at
    // window end. We surface "first report" as the ETA.
    $eta = $s['tick'] * 60 + 180;
    $label = "Watching for {$s['hours']}h — first report in ~" . v_plan_humantime($eta)
           . ", final report in ~" . v_plan_humantime($dur);
  } else {
    // One-shot: fan-out to reference models + aggregator + synthesis,
    // measured 2–6 min on the shared CPU studio; 3 min is the median.
    $eta = 180;
    $label = 'Ready in ~' . v_plan_humantime($eta);
  }
  return ['study' => $s['study'], 'hours' => $s['hours'] ?? 12, 'tick' => $s['tick'] ?? 15,
          'eta_seconds' => $eta, 'eta_label' => $label];
}

function v_plan_humantime(int $sec): string {
  if ($sec < 90) return $sec . 's';
  if ($sec < 5400) return round($sec / 60) . ' min';
  $h = $sec / 3600;
  return round($h * 2) / 2 . ' h';
}
