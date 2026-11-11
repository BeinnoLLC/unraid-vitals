

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { recallOf, falseRateOf, scoreLine } from '../lib/eval-scoring.mjs';

test('an errored run scores n/a, never a perfect score', () => {
  
  
  const R = { error: 'fetch failed', detected: 0, missed: 0, false: 0, runs: 0, okOnly: true, examples: [] };
  assert.equal(recallOf(R), null, 'no answer is not a correct answer');
  assert.equal(scoreLine(R).recallStr, 'n/a', 'the report must not print 100%');
});

test('a clean run on a healthy fixture scores 100%', () => {
  const R = { detected: 0, missed: 0, false: 0, runs: 1, okOnly: true, examples: [] };
  assert.equal(recallOf(R), 1);
  assert.equal(scoreLine(R).recallStr, '100%');
});

test('a run that found only non-ok findings scores 0, not 1', () => {
  
  const R = { detected: 0, missed: 0, false: 1, runs: 1, okOnly: false, examples: [] };
  assert.equal(recallOf(R), 0);
  assert.equal(scoreLine(R).recallStr, '0%');
});

test('recall is detected / (detected + missed)', () => {
  assert.equal(recallOf({ detected: 2, missed: 2, okOnly: false }), 0.5);
  assert.equal(scoreLine({ detected: 1, missed: 0, okOnly: false }).recallStr, '100%');
  assert.equal(scoreLine({ detected: 0, missed: 1, okOnly: false }).recallStr, '0%');
});

test('falseRate is false findings per run, and 0 when nothing ran', () => {
  assert.equal(falseRateOf({ false: 2, runs: 4 }), 0.5);
  assert.equal(falseRateOf({ false: 0, runs: 0 }), 0, 'never divide by zero');
  assert.equal(falseRateOf({}), 0);
});

test('scoring tolerates partial records without throwing', () => {
  
  
  assert.equal(recallOf({}), 0, 'no findings and not okOnly == 0%');
  assert.equal(scoreLine({}).recallStr, '0%');
});
