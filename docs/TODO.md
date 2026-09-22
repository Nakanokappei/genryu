# TODO

Things decided but not built yet, with the condition they wait for.

## After the production deployment

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
