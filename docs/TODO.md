# TODO

Things decided but not built yet, with the condition they wait for. The
principle behind the human-facing ones: human on the loop, not in the
loop — nothing waits for a person, a person looks after the fact.

## After the production deployment

- **Keep secrets out of reach at the web server** (2026-09-25). The
  document root must be `public/` (then `.env` is outside it). Laravel
  answers 404 for dotfiles and secret-looking files
  (`App\Http\Middleware\BlockSensitivePaths`) and `public/.htaccess`
  does the same on Apache; on nginx add the equivalent, e.g.
  `location ~ /\.(?!well-known) { return 404; }`, and check with
  `curl -I https://<host>/.env`.
- **A daily mail that asks for a human look** (decided 2026-09-22). The
  screening runs without anyone reviewing (a 要確認 gets one second pass
  by the next model up and is decided); still, once a day, one mail
  should point a person at what deserves a look: documents adopted or
  rejected that day (counts and titles, the second-pass ones marked),
  screenings that failed, sources whose fetch failed or whose bodies came
  out short (本文が短い), and the cache / cost figures. Waits for the
  production environment, because no mail can be sent from here yet.
  Likely shape: a scheduled command (`schedule:run`) rendering one
  Markdown mail through the framework's mailer; no per-event mails.

## If the PoC calls for it

- **Background from outside the model** (Wikipedia EN with pinned
  revisions, as ChatGPT's dossier design describes). Cut on 2026-09-23:
  the PoC asks whether the model's own general knowledge is enough, so
  `knowledge` items are marked and can be judged first. Build this only
  if those items prove wrong often enough to matter.

## When human decisions start to accumulate

- **Human decisions as few-shot examples for the screening** (idea
  2026-09-22). A person's verdict on a document (adopt / reject, with a
  line of reason) is worth more than any prompt text: keep it on the
  document, and give the screening the verdicts on earlier documents of
  the same source as examples. Since the prompt is cached as one block,
  the examples go after the cache breakpoint, before the document, so the
  fixed prompt stays cached and only the examples vary per source.
  The verdict is recorded on the document screen since 2026-09-22
  (人の判定, `documents.human_decision`); the daily mail above is where a
  person would come from.
- **Then, examples chosen by similarity** (same idea): embed the
  documents (title + first part of the body) and, for a document to
  screen, pick the few human-decided documents nearest to it — across
  sources, not only its own — instead of the latest ones of the source.
  Needs an embedding column (pgvector) and one embedding call per
  document at fetch time.

- **Run the scheduler in production** (2026-09-25): `media:prune-images`
  is scheduled daily in `routes/console.php` to delete the top images
  drawn more than 30 days ago, but nothing runs the scheduler locally.
  In production, run `php artisan schedule:run` every minute (cron or the
  platform's scheduler). Until then it can be run by hand.
