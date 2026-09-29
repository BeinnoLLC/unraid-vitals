#!/usr/bin/env node
/**
 * unraid-vitals agent — on-demand share comment generator.
 *
 * Invoked per-click from the UI (via actions.php), not on a schedule. Reads
 * a directory listing sample from the share and asks the model to write a
 * short, human comment describing what the share appears to hold.
 *
 * Usage: node share-comment.mjs <shareName>
 */
import { execFileSync } from 'node:child_process';
import { makeAnalysisAgent, callAnalyze } from './lib/smythos-client.mjs';
import { setShareComment } from './lib/db.mjs';

const share = process.argv[2];
if (!share || !/^[\w.\- ]+$/.test(share)) {
  console.error('usage: share-comment.mjs <shareName> (alnum/space/dot/dash/underscore only)');
  process.exit(1);
}

function sampleListing(sharePath, maxEntries = 60) {
  try {
    // Depth-2 listing, directories first, so the model sees real structure
    // (top-level folders + a peek inside) without walking the whole tree.
    const out = execFileSync('/usr/bin/find', [sharePath, '-mindepth', '1', '-maxdepth', '2'],
      { encoding: 'utf8', timeout: 8000 });
    return out.split('\n').filter(Boolean).slice(0, maxEntries)
      .map(p => p.replace(sharePath + '/', ''));
  } catch {
    return [];
  }
}

async function main() {
  const sharePath = `/mnt/user/${share}`;
  const entries = sampleListing(sharePath);
  if (!entries.length) {
    setShareComment(share, '', 'empty');
    console.log(`[${share}] no readable entries — left comment empty`);
    return;
  }

  const system = `You write short, factual one-sentence comments describing what a NAS share contains, based on a sample of its file/folder listing. Be specific about content type (media, backups, documents, app data, code, etc.), never generic. Output ONLY the comment sentence, no quotes, no preamble, max 140 characters.`;
  const user = `Share: ${share}\nSample listing (depth 2, up to 60 entries):\n${entries.join('\n')}`;

  const comment = (await callAnalyze(
    await makeAnalysisAgent('Vitals-ShareComment',
      'You write short, factual one-sentence descriptions of NAS shares based on a sample file listing.',
      { maxTokens: 120, temperature: 0.3 }),
    system, user
  )).trim().replace(/^["']|["']$/g, '').slice(0, 200);
  setShareComment(share, comment, 'done');
  console.log(`[${share}] ${comment}`);
}

main().catch(e => {
  setShareComment(share, '', 'error');
  console.error(`[${share}] FAILED: ${e?.message || e}`);
  process.exit(1);
});
