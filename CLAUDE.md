# shelfwood/health — the health-document contract

Laravel package (Pest, `composer test`) that produces `/api/v1/health` as `{ status, checks[], metrics? }`. Consumed by `prj-more-apartments` via a VCS repository entry (not Packagist); a fix here reaches every More Apartments site only after `composer update shelfwood/health` there plus a deploy. Tag releases `vX.Y.Z`.

## Estate

Not a monitor resource itself; every check in this package feeds `applications:<site>` for the More Apartments estate. Probe keys must be collision-free (random bytes, never `time()`): two concurrent health hits in one second raced on a shared key and produced "Cache value mismatch" on 389/754 monitor ticks in 2026-09.
Before changing a check's verdict: `estate history applications:<site> --days 7` to see what it currently does live (skill `estate`); judgement about staging vs production folding lives in the monitor, not here.
