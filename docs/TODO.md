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
