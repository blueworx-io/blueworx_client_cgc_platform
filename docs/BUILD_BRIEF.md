# CGC Tournament Platform: Demo Build Brief

> Source: [Claude doc](https://claude.ai/artifact/D1vwsUu5ztFoP2V3hW2u5V). Work is tracked in Forge under "CGC Demo Platform" (cards M0–M10). Demo to Seb (CGC CEO): **Friday 11 December 2026**.

**Bottom line:** Claude Code should build one WordPress plugin, working name **`cgc-championship`**. It should run a full Creator Golf Club season on real logic, from entry and mock payment through a 64-team group draw, self-scheduling, scoring, confirmation and disputes, and a Main plus 2nd Chance bracket, then on to Regional Finals or a Rest-of-World Group Final, the Grand Final and the Golden Ticket draw. It also needs a "time-travel" demo console so Seb (CGC's CEO) can jump to any date in the season and watch it work. CGC's request for quote says the agency's job is to "take that demo and convert it into the final, production-ready product — not to design it from scratch" (CGC RFQ). So the demo has to be correct in behaviour, not just in appearance. The existing Vercel prototype only clicks through a single 16-team club knockout ([Vercel demo](https://cgc-tournament-platform-brief-fa12a.vercel.app/)). The real championship is harder in four ways: regional groups, a 6-round Main Bracket plus a 5-round 2nd Chance Bracket on a fixed date grid, a tiered handicap system, and three different routes to the finals ([Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs); [Tournament Rules](https://www.creatorgolfclub.com/tournament-rules)). Four findings shape the build. First, Wedge has no public API, but Big Wedge owns CGC, so the scoring link is an internal job between two teams in the same group, with no commercial or data-sharing barrier. By contrast, there is no self-serve WHS handicap API in the UK or Ireland. The demo mocks both behind clean interfaces ([Wedge Terms](https://app.bigwedgegolf.com/terms); [Golf Business News](https://golfbusinessnews.com/news/corporate/golf-genius-approved-to-deliver-whs-for-home-nations/)). Second, CGC's own handicap rule applies the allowance before subtracting the lowest handicap, which is the method the home unions dropped for four-ball in April 2024, so the method must be a setting ([Scottish Golf](https://scottishgolfltd.zohodesk.eu/portal/en/kb/articles/ho-7-6-2024)). Third, any group that needs byes can break CGC's promise that every team plays two matches, unless the 2nd Chance rule is defined as "loser of first *played* match". Even then, it can still overflow the 5-round 2nd Chance calendar. Fourth, several core rules (who drops into 2nd Chance, Regional Final format, how many teams qualify for the Grand Final) are not published and must be built as settings and confirmed with CGC.

> **How to use this brief:** paste it into Claude Code as the build specification. Items marked **[CONFIRMED]** come from CGC's published rules or the RFQ. Items marked **[INFERRED]** are design decisions or deductions that must be built as editable settings with the stated default. Items marked **[OPEN]** are listed in section 10 for CGC to answer.

---

## 1. Purpose: Seb must watch one season run from entry to Vilamoura

The demo has two audiences. Seb needs to see the championship running end to end on CGC's real rules, not a mock-up. The production dev team, later, needs a codebase whose data model, rules engine and API are already right, so its work is hardening, scaling and connecting real integrations. CGC's motivation is to own the platform, keep running costs predictable as player numbers grow, and potentially offer it to other clubs and organisers later. It needs to be live for the January 2027 season, and CGC is open to a phased launch with admin tools first (CGC RFQ). That last point means multi-tenant readiness (a `tenant_id` on every table) belongs in the demo from day one, even though only one organiser will use it.

The demo succeeds when someone can walk Seb through the moments below in one sitting, switching role and date from a demo console, with every number on screen produced by the plugin's own logic.

| # | Demo moment Seb must see working | What proves it is real |
|---|---|---|
| 1 | **Entry**: a captain signs up, picks a playing location, pays the £150 team fee (simulated), invites a partner and two reserves, and uploads handicap proof | New team appears in the admin registration funnel. Payment row is created with status *Paid (demo)*. Handicap goes into the approval queue |
| 2 | **Golden Ticket draw**: admin runs the draw of 4 tickets among eligible UK/Ireland/Europe teams paid before the cut-off | Draw is reproducible from a stored random seed. Winners are notified. An unanswered ticket is reassigned after its deadline |
| 3 | **Groups/cohorts**: admin builds regional groups of about 64 teams from playing locations | Group sizes, regions and Regional Final venue mapping are visible. Teams can be moved between groups with an audit entry |
| 4 | **Draw**: admin runs a seeded or random draw, previews it, swaps two teams, publishes | Bracket shows seeds 1v64, 32v33 and so on. Home team is random in Round 1. The swap is in the audit log |
| 5 | **Self-scheduling**: each team proposes 3+ dates (2 on separate weekends). The opponent accepts or counters. Silence escalates at 48h. Deadlock goes to a coin toss | Proposal history is stored. Escalation notifications fire. Admin coin toss is recorded with its random seed |
| 6 | **Scoring**: the match is "played in Wedge" via a **Simulate Wedge result** button that sends a signed webhook, or the captain types the scorecard in manually | Hole-by-hole net better-ball is computed from CGC handicaps. Result "3&2" is derived, not typed |
| 7 | **Confirmation and disputes**: opponent confirms, or disputes within 48h. Admin rules on it. Non-submission leads to forfeit | Dispute queue with deadline (SLA) timer. Every override writes before/after to the audit trail |
| 8 | **Main + 2nd Chance brackets**: winners auto-advance. Losers of their *first played* match drop into 2nd Chance, which runs one round behind | After Round 1 closes, 32 teams appear in 2nd Chance R1 for a 64-team group. Byes are handled correctly |
| 9 | **Regional Finals / RoW Group Final**: UK/Europe qualifiers (Main winner, 2nd Chance winner, Golden Tickets) go to their region's venue. RoW groups play a 7th-match Group Final, with the Main Bracket team at home | Qualification route is stored per team. The 2nd Chance +1 shot penalty is applied at Regionals only |
| 10 | **Grand Final**: qualifiers play a configurable 3-round event at Vilamoura | Leaderboard across mixed formats. The 2nd Chance penalty is removed |
| 11 | **Time travel**: jump to "Round 3 mid-way", "2nd Chance R5", "Regional Finals week" | `wp cgc simulate --to=<date>` and an admin date slider replay deadlines, forfeits and auto-advances deterministically |

Two non-goals keep the scope honest. **Payments are simulated only:** no Stripe keys, no real money, but the same `PaymentGateway` interface a real provider will implement. **Real integrations (Wedge, WHS, email delivery) are mocked:** each has a production-shaped interface and a demo adapter, so the production dev team can swap in real adapters without touching business logic.

**This is a demo, built for handover.** It is not the production system. A dev team will convert it into production, so Claude Code must build it to be easy to take over:

- **Real logic, demo-grade hardening.** The rules engines (handicap, brackets, scheduling, results) must be correct and tested. Scale, security hardening, real integrations and hosting are left to the dev team. Write every shortcut down; don't hide it.
- **`HANDOVER.md` in the repo root**, kept up to date at every milestone. It lists: what is real vs mocked, each mock and the interface a real version must implement, known shortcuts and limits, the data model (tables and relationships), how to run the demo (`wp cgc seed`, `simulate`, `reset`), how to run the tests, and the open questions from section 10 with the default currently used.
- **Readable, conventional code.** Follow WordPress coding standards, use clear names, and put a short docblock on every class and REST route. Avoid clever one-offs a new team would need to reverse-engineer.
- **Tests as the spec.** The unit tests for the handicap, bracket and match-state engines double as the rules spec the dev team builds against.
- **Demo-only code fenced off.** The time-travel console, simulators and seed data live in their own `Demo` namespace behind a `CGC_DEMO_MODE` switch, so the dev team can switch them off or strip them out cleanly.

---

## 2. Championship rules the engine must encode, and which ones are still guesses

CGC's published structure has four stages: entry from January to April, season-long group knockout matchplay from 27 April to 27 September, Regional Finals in October (UK and Europe only), and the Grand Final in Vilamoura, Portugal, from 23 to 26 November 2026 ([How it works](https://www.creatorgolfclub.com/how-it-works); [Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). The RFQ simplifies this to "Local Qualifiers → Regional Finals → Grand Final". It also asks the platform to support knockout and Stableford formats, 1v1, 2v2 and 4v4 teams, and open and invite-only events, all configurable by admins (CGC RFQ). The engine should therefore be generic: stages, brackets, formats and handicap profiles stored as configuration. CGC's 2026 rules ship as the default "CGC Championship" template.

### The 2026 date grid is the default template

Main Bracket rounds run 27 Apr–24 May, 25 May–21 Jun, 22 Jun–19 Jul, 20 Jul–16 Aug, 17 Aug–6 Sep and 7–27 Sep. The 2nd Chance Bracket runs one round behind: 25 May–21 Jun, 22 Jun–19 Jul, 20 Jul–16 Aug, 17 Aug–6 Sep and 7–27 Sep. The RoW Group Final runs 28 Sep–20 Oct ([Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). Matches can be played until **23:59 on the round's last day** ([Schedule](https://www.creatorgolfclub.com/schedule)). No 2027 calendar is published. The demo should therefore seed a "2027 season" by moving the 2026 grid on by a year, keeping Monday starts, and flag it as a placeholder **[INFERRED]**.

### Rules table

| Area | Rule to encode | Status / source |
|---|---|---|
| Stages | Entry → Group knockout (Main 6 rounds + 2nd Chance 5 rounds) → Regional Final (UK/Europe) or Group Final (RoW) → Grand Final | **[CONFIRMED]** ([How it works](https://www.creatorgolfclub.com/how-it-works); [Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)) |
| Routes to Regional Final | Win all 6 Main matches; or win all required 2nd Chance matches; or win a Golden Ticket | **[CONFIRMED]** Rule 9.1 ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules)); Golden Ticket ([Prizes](https://www.creatorgolfclub.com/prizes)) |
| Group size | About 64 teams per group, so 6 Main rounds produce exactly one unbeaten team | **[INFERRED]** from bracket maths (2^6 = 64). Not published |
| 2nd Chance entrants | Losers of their **first played** Main match (a Round 1 loser, or a team with a Round 1 bye that loses in Round 2) | **[INFERRED]**. Site only says "losing teams move to the 2nd Chance Bracket" ([Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). The two-match guarantee ([How it works](https://www.creatorgolfclub.com/how-it-works)) and the 5-round count only work if later-round Main losers are eliminated. **[OPEN]** |
| 2nd Chance format | Single elimination, 5 rounds (32 → 1 for a 64 group) | **[INFERRED]** |
| RoW finish | No Regional event. Main winner plays 2nd Chance winner in a 7th-match Group Final. Main Bracket team is automatically at home. Winner goes straight to the Grand Final | **[CONFIRMED]** Rules 9.3, 9.7 ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules)) |
| Regional Final | One day, one round, format "to be confirmed". "Top teams" qualify for the Grand Final | **[CONFIRMED]** that it is TBC (Rule 9.4). Demo default: four-ball Stableford, top 2 qualify **[INFERRED]** |
| 2nd Chance penalty | 2nd Chance qualifiers get a one-shot penalty to playing handicaps at Regionals. It is removed if they reach the Grand Final | **[CONFIRMED]** Rule 9.2. Per-player vs per-team application is **[OPEN]** |
| Grand Final | Three rounds of varied formats. Venues page lists Vilamoura's Old Course, Millennium and Pinhal, while the rules still say "location to be determined" | **[CONFIRMED]** ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules); [Venues](https://www.creatorgolfclub.com/venues)). Formats **[OPEN]** |
| Match format | 18-hole four-ball better-ball net matchplay. Lower better net score wins the hole, equal halves it. R&A/USGA rules apply | **[CONFIRMED]** Rule 6.1–6.2 |
| Course | Any course with a full 18-hole scorecard. A 9-hole course is acceptable if it has one | **[CONFIRMED]** Rule 5.6 |
| Home/away | Round 1 random. Later rounds: the team that **finished its previous match first** is home. Home team picks the course (its own, or a neutral course by agreement) | **[CONFIRMED]** Rule 5.2. Bye and forfeit timestamps **[OPEN]** |
| Travel | Groups formed to minimise travel. Meeting at a halfway point is encouraged | **[CONFIRMED]** Rule 5.8 |
| Scheduling | Each team proposes **at least 3 dates, 2 on separate weekends**. Unco-operative conduct may forfeit the match | **[CONFIRMED]** Rule 5.4; T&Cs 2.3 |
| Non-response | Wait at least 48h, then message, then call/text, then contact support | **[CONFIRMED]** ([Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). Automated as 48h escalation steps **[INFERRED]** |
| Deadlock | Organiser coin toss, result final. **Sources disagree**: the FAQ implies it settles the date, Rule 5.7 says it decides the **winner** | **[CONFIRMED]** conflict. Build `coin_toss_mode = winner | date`, default `winner` |
| Late cancellation | Cancelling a confirmed match with under 24h notice, without a valid emergency, may forfeit it. Organiser decides | **[CONFIRMED]** Rule 5.5 |
| Suspended match | Resume from the same hole and score. If it can't resume within 7 days or before round end, teams may agree to replay, otherwise coin flip | **[CONFIRMED]** Rule 5.15 |
| Result submission | Every player's score on every hole goes into the official digital scorecard. Captains or scorekeepers submit **and confirm within 48h** or the match may be forfeited. Paper fallback by email to support, copying the other captain | **[CONFIRMED]** Rules 2.8, 5.10 |
| Tie after 18 | Sudden death where the course allows. Otherwise a putt-off: every player holes one putt from 7 ft, most holed wins, then repeat at 8, 9, 10 ft | **[CONFIRMED]** Rule 5.11 |
| Team | Up to 4 players: captain, second player, up to 2 reserves. Male, female or mixed. Reserves may sub, but captain **or** second player must play every match. One team per player | **[CONFIRMED]** Rules 3.1–3.3. Players can be added and removed during the tournament ([Entry details](https://www.creatorgolfclub.com/entry-details)) |
| Pros | Allowed if paired with an amateur at all times. Play off 0. One tee further back, subject to opponent approval | **[CONFIRMED]** Rules 1.1–1.2, 5.14 |
| Age | Minimum 16 at tournament start. Under-18s must have a parent or guardian **on the same team** | **[CONFIRMED]** Rules 1.3–1.4; T&Cs 6.5 |
| Tees | Middle/standard tees (e.g. yellow) unless agreed. Men's/women's tees follow the profile gender | **[CONFIRMED]** Rule 5.14 |
| Equipment | Buggies and distance devices allowed (no elevation). AI glasses banned. No caddies | **[CONFIRMED]** Rules 5.12–5.13. Show in pre-match checklist only |
| Entry fee | £75 per person or £150 per team. Non-refundable except when a region is cancelled for low numbers | **[CONFIRMED]** ([Entry details](https://www.creatorgolfclub.com/entry-details); [T&Cs](https://www.creatorgolfclub.com/terms-and-conditions) 5.2, 8.4). Note the Vercel demo shows £90, which is wrong for CGC |
| Golden Tickets | 4 tickets, each direct entry to one Regional Final chosen by the Organiser. Eligible: UK, Ireland, Europe teams paid in full before 23:59 on 8 April. One automatic entry per paid team. Random draw. Winners notified before the start. Unanswered tickets reassigned. Non-transferable, travel not included, must attend. Winners may still play qualifying matches | **[CONFIRMED]** ([Prizes](https://www.creatorgolfclub.com/prizes); [T&Cs](https://www.creatorgolfclub.com/terms-and-conditions) cl.10). What happens if a ticket winner also qualifies on the course is **[OPEN]** |
| Locations | Entrants pick one: UK, Australia (Brisbane, Melbourne, Sydney), Belgium, Canada (Toronto), Ireland, Netherlands, UAE (Abu Dhabi & Dubai), USA (NYC) | **[CONFIRMED]** ([Entry details](https://www.creatorgolfclub.com/entry-details)) |
| UK regions | South West → Celtic Manor (1 Oct); North West → Worsley Park (12 Oct); Midlands → Breadsall Priory (15 Oct); South East → The Grove (20 Oct) | **[CONFIRMED]** ([Venues](https://www.creatorgolfclub.com/venues); [Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). How Ireland/Belgium/Netherlands and Scotland/Wales/N. Ireland map to regions is **[OPEN]** |
| Travel allowances | Up to £250 per team for Regional Finals travel from outside UK mainland. Grand Final economy travel up to £300 per team (UK/Europe) or £2,000 (other cohorts), 3 nights 4-star, 3 rounds of green fees | **[CONFIRMED]** Rules 9.6, 9.9. Store as region attributes for reporting only |

### The "drop after first played match" pitfall

A naive engine sends "Round 1 losers" to 2nd Chance. In a group with byes, a top seed that skips Round 1 and then loses in Round 2 has played **one** match and is out. That breaks the published promise of "at least two matches against different opponents" ([How it works](https://www.creatorgolfclub.com/how-it-works)). The rule must be "loser of first played match". That fixes fairness but creates a calendar problem, worked out in section 6. Bye teams that lose in Main Round 2 only become known when 2nd Chance Round 1 is already ending, so they can only join at 2nd Chance Round 2. That round holds 16 teams in a 5-round bracket. The demo engine must detect this overflow and offer the admin a defined policy, rather than silently producing an invalid bracket.

---

## 3. Handicap engine: two methods, one setting, exact maths

The handicap engine is the part of the demo most likely to be wrong in a subtle way. CGC's own rules and current WHS four-ball guidance compute strokes in different orders, and the two orders give different answers.

### CGC's published method (Rule 6.3 plus Rules 2.1–2.6)

CGC requires a verifiable WHS index with **at least 3 rounds in the prior 12 months** and caps the index at **28.0** for all genders. A player above 28 may enter but must reduce to 28 in their CGC account ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules) 2.1, 2.6; [Handicap Policy](https://www.creatorgolfclub.com/handicap-policy)). Tiers depend on rounds recorded in the 12 months before the start date (27 April). **Tier 1 (12+ rounds) plays at 90%. Tier 2 (3–11 rounds) plays at 75%.** Players can move up to Tier 1 mid-season, and tier is set at the start of each match ([Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs); [Tournament Rules](https://www.creatorgolfclub.com/tournament-rules) 2.2–2.5). Plus handicaps reverse the percentages: Tier 1 gets 75% and Tier 2 gets 90%, so a +4 course handicap becomes +3 or +3.6 (Rule 2.3). The calculation steps in Rule 6.3 are: cap the index at 28, compute course handicap from the player's own tee as `Index × Slope/113 + (CR − Par)`, apply the tier allowance, round to the nearest whole number, subtract the lowest playing handicap in the group, and allocate strokes by the difference ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules)). The Handicap Policy adds a discretionary **Exceptional Score Reduction** ("Tier 3"), cutting a player's index by about 50%. It also allows retrospective sanctions and a 48-hour written appeal window ([Handicap Policy](https://www.creatorgolfclub.com/handicap-policy)).

### Current WHS four-ball method in GB&I (since 1 April 2024)

The home unions changed four-ball matchplay. Each player's **rounded** course handicap is compared with the lowest in the group first, and 90% is applied to the **difference**. "The old method, where each player took 90% of their own Course Handicap, no longer applies from 1 April 2024" ([Scottish Golf](https://scottishgolfltd.zohodesk.eu/portal/en/kb/articles/ho-7-6-2024); [National Club Golfer](https://www.nationalclubgolfer.com/whs/fourball-match-play-world-handicap-system-changes/)). The USGA confirms the same change worldwide ([USGA 2024 WHS Reference](https://digital-pd.usga.org/content/dam/usga/pdf/Handicap/2024%20WHS%20Reference%20for%20Clubs.pdf)). CGC's Rule 6.3 order (allowance first, then subtract lowest) is therefore the **pre-2024** shape, with CGC's own tier percentages swapped in. That is allowed: the Rules of Golf let a Committee set handicap limits and vary the "strokes off the lowest" approach in the Terms of the Competition ([R&A Committee Procedures 5](https://www.randa.org/rog/committee-procedures/5); [R&A Appendix C](https://www.randa.org/roh/appendices/appendix-c)). But it must be a deliberate, visible setting.

### Settings the engine exposes (a "handicap profile" per stage)

| Setting | Values | CGC default |
|---|---|---|
| `index_cap` | number or null | 28.0 |
| `min_rounds_12m` | int | 3 |
| `allowance_source` | `tier` (CGC tiers) / `format` (WHS table) | `tier` |
| `tier_rules` | list of {min_rounds, pct, plus_pct} | T1: ≥12 → 90 (plus 75); T2: 3–11 → 75 (plus 90) |
| `allowance_applies_to` | `course_handicap` / `index` | `course_handicap` **[CONFLICT, see below]** |
| `method` | `pct_then_diff` (CGC Rule 6.3, old WHS) / `diff_then_pct` (WHS GB&I 2024) | `pct_then_diff` |
| `rounding` | `half_up` (x.5 away from zero) / `half_even` | `half_up` |
| `low_reference` | `all_players_in_match` / `per_side` | `all_players_in_match` |
| `format_allowances` | singles MP 100, four-ball MP 90, foursomes 50% of combined difference, individual SP 95, four-ball SP/Stableford 85 | as listed ([R&A Appendix C](https://www.randa.org/roh/appendices/appendix-c)) |
| `pro_handling` | `ph_zero` / `index_zero` | `ph_zero` **[OPEN]** |
| `penalty_shots` | int, scope `per_player` / `per_team`, stage-specific | Regional Final: 1, `per_player` **[OPEN]** |
| `freeze_at` | `match_start` / `stage_start` / date | `match_start` (Rule 2.4) |
| `esr_pct` | per-player override, e.g. 50 | null |

**The allowance conflict:** CGC's Rule 2.2 says Tier 1 is "90% of course handicap", but the FAQ and entry page say "90% of handicap index". Rule 6.3's step-by-step order applies it to the course handicap ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules); [Rules & FAQs](https://www.creatorgolfclub.com/rules-and-faqs)). The worked example below shows the two readings can differ by a stroke, so `allowance_applies_to` must be a setting with Rule 6.3 as the default.

Scotland's April 2026 notice also lets clubs choose 75–90% for four-ball stroke play and Stableford, and 85–100% for singles counting events, while leaving matchplay unchanged ([Scottish Golf notice](https://www.nationalclubgolfer.com/wp-content/uploads/2026/04/change-to-recommended-handicap-allowances-v2.pdf)). That is further reason to keep every percentage editable.

### Algorithm (pseudo-code Claude Code should implement in `HandicapEngine`)

```
for each player p in match lineup:
    hi  = p.is_pro && pro_handling=index_zero ? 0 : p.index
    if p.esr_pct: hi = hi * (1 - p.esr_pct/100)
    hi  = min(hi, index_cap)                          # plus index (negative) unaffected
    pct = allowance(p)                                # tier pct (plus_pct if hi<0) or format pct
    if allowance_applies_to == index:
        ch_raw = (hi*pct) * slope/113 + (cr - par)
        ph     = round(ch_raw)
    else:
        ch_raw = hi * slope/113 + (cr - par)          # player's own tee & gender rating
        if method == pct_then_diff: ph = round(ch_raw * pct)
        else:                       ch = round(ch_raw)   # diff_then_pct handled below
    if pro && pro_handling==ph_zero: ph = 0
    ph -= penalty_shots(p, stage)                     # 2nd-chance penalty = one fewer stroke

if method == pct_then_diff:   low = min(ph);  strokes[p] = ph[p] - low
if method == diff_then_pct:   low = min(ch);  strokes[p] = round(pct_format * (ch[p] - low))

strokes_on_hole(p, si) = floor(s/18) + (si <= s mod 18 ? 1 : 0)       # s >= 0
# plus/negative s: give back strokes starting at SI 18 (R&A Appendix C)
```

Store a **snapshot per player per match** (index, rounds, tier, tee, slope, CR, par, course handicap raw and rounded, allowance, playing handicap, strokes, method and settings version). Any result can then be audited later, which Rule 2.10's "organiser may adjust any handicap at any stage" makes necessary.

### Worked example (computed for this brief)

Tee: Slope 125, Course Rating 71.2, Par 72. Players: A index 4.2 (Tier 1), B 12.8 (Tier 2), C 18.5 (Tier 1), D 31.0 (Tier 2, capped to 28.0).

| Player | Index used | Course hcp (raw) | Allowance | PH raw → rounded | **Strokes (CGC Rule 6.3)** | Strokes if allowance on index | Strokes WHS GB&I 2024, no tiers/cap |
|---|---|---|---|---|---|---|---|
| A | 4.2 | 3.846 | 90% | 3.461 → 3 | **0** | 0 | 0 |
| B | 12.8 | 13.359 | 75% | 10.019 → 10 | **7** | 7 | 8 |
| C | 18.5 | 19.665 | 90% | 17.698 → 18 | **15** | 15 | 14 |
| D | 28.0 (cap) | 30.173 | 75% | 22.630 → 23 | **20** | 19 | 26 |

Under CGC's rule, D receives 20 strokes: one on every hole and a second on SI 1 and 2. B receives one on SI 1–7, and C one on SI 1–15. Applying the allowance to the index instead gives D 19. Plain WHS without CGC's cap and tiers gives D 26. These differences are why the setting and the per-match snapshot matter. The research notes give a second check case for the plain WHS method: indexes 4.2/12.8/18.5/26.0 on the same tee should give 0/8/14/22 under the 2024 method and 0/9/15/22 under the old method. Use both as unit tests.

### Stableford and other formats

Stableford points per hole are **net double bogey or worse 0, bogey 1, par 2, birdie 3, eagle 4, albatross 5**, with strokes applied by stroke index before scoring ([Golf Monthly](https://golfmonthly.com/features/the-game/golf-stableford-explained-77797); [R&A Rule 21](https://www.randa.org/rog/the-rules-of-golf/rule-21)). The formula is `points = max(0, 2 + par − (gross − strokes_on_hole))`, and a pick-up scores 0. In four-ball Stableford the pair scores the better partner's points per hole. Each player's PH is `round(0.85 × CH)`, with no "off the low" step because it is stroke-play based. Singles matchplay is 100% of the difference. Foursomes gives the higher side 50% of the difference in combined course handicaps ([R&A Appendix C](https://www.randa.org/roh/appendices/appendix-c)). WHS has no "4v4 better-ball" allowance, so 4v4 should be modelled as two simultaneous four-ball matches (team point per match won, half per halved) or as a team Stableford. Either way, the allowance is set per format. Course handicap can exceed 54 while the index cannot ([Golf Monthly](https://golfmonthly.com/features/the-game/what-is-the-maximum-golf-handicap-205313)), so don't cap course handicap.

Course data needs per-tee, per-gender Par, CR and Slope, plus 18 holes each with par and SI. Validate on entry: slope between 55 and 155, SI values a permutation of 1–18, and hole pars summing to the total. Commercial course-data APIs exist (GolfCourseAPI: 30,000+ courses, $9.99/month Pro tier), but their UK SI coverage is unverified ([GolfCourseAPI](https://golfcourseapi.com/)). The demo should seed a dozen real-looking UK tees by hand and let admins and home captains add a course from a scorecard.

---

## 4. Functional scope by role, matching the Vercel screens and every RFQ bullet

The Vercel prototype already defines the look and the role switcher: Super Admin, Tournament Admin, Player and Public site. It uses a deep-green and cream palette, condensed uppercase headings and avatar-initial circles ([Vercel demo](https://cgc-tournament-platform-brief-fa12a.vercel.app/)). The plugin should reproduce those screens with live data, then add the missing championship-scale screens. The tables mark each screen as **Parity** (exists in Vercel) or **New** (needed for CGC's real rules or the RFQ).

### Super Admin (platform / future SaaS)

| Screen | Contents | Parity/New |
|---|---|---|
| Tournaments | All events across organisers. KPIs (live, registering, players, GMV). Table: tournament, organiser, format, entrants, status, Open/Suspend. **+ New tournament from template** ("CGC Championship 2027") | Parity + template |
| Admins & Users | Name, email, role, scope (platform / tournament / region / group). Invite admin | Parity + group scope |
| Billing & Payments | Static mock: processed 30 days, 5% platform fee, payouts | Parity (mock) |
| Platform Settings | Branding (logo, colour). Defaults: require WHS verification, match chat, public leaderboards, auto-advance. Integrations panel with **mode switches** (Payments: Mock/Stripe-disabled; Handicap: Manual/Mock lookup; Email: Log-only/wp_mail; Wedge: Simulator/Webhook) | Parity + mock switches |
| **Demo console** | Current demo date, time-travel slider with presets, Simulate to date, Reset, Snapshot save/restore, "Simulate Wedge result" tools, email outbox viewer | New |

### Tournament Admin ("control room")

| RFQ bullet / need | Screen and behaviour | Parity/New |
|---|---|---|
| Set up tournaments, stages, formats & cohorts | Stage builder: Group stage (Main + 2nd Chance), Regional Final, Group Final, Grand Final. Per stage: format (knockout MP / Stableford / stroke), team size (1/2/4), handicap profile, round date grid, qualification rules. Registration mode open / invite-only (invite codes, allow-list) | New |
| Regions & groups | Regions (UK SW/NW/Midlands/SE, RoW cities) → Regional Final venue/date. **Group builder** suggests groups of 64 from locations (by distance to home club). Drag teams between groups. Shows bye warnings | New |
| Bracket engine: automatic draw, seeded or manual | Draw screen per group and bracket. Seeding: random / combined index / manual order. Preview → Swap → Publish. "Re-run draw" only before publish | Parity, extended |
| Second-chance bracket alongside main | 2nd Chance tab per group, populated automatically as first-played-match losers arrive. Bye/overflow policy banner | New |
| Approve players, matches, submitted results | Queues: handicap verifications (evidence image, claimed index/rounds, approve/reject/adjust), registrations (invite-only), results awaiting confirmation, disputes (SLA timer, both scorecards, message both teams, "Rule & resolve") | Parity + handicap queue |
| Manual overrides for walkovers & forfeits, with audit trail | Match override drawer: set winner, method (walkover, forfeit, double forfeit, coin toss, putt-off, admin decision), reason (required). Writes audit before/after. Re-cascades bracket | Parity, extended |
| Manage players: search, filter, move cohorts, disqualify | Entrants & Teams table (team, captain, location, group, WHS status, payment, status, route). Filters. Bulk move group. Disqualify (cascades as forfeit). Export CSV | Parity, extended |
| Venue management | Venues (Regional Final courses, Grand Final courses) with date, capacity, tee data. Assign qualifiers. Tee-time sheet for finals | New |
| Send announcements by cohort | Compose: audience = all / region / group / bracket / round / "unplayed with <72h to deadline" / captains only. In-app + email (logged). Read receipts | Parity, extended |
| Process refunds and track registration pipeline | Payments: per-team charges, Paid/Pending/Refunded, **Refund (mock)** button with reason. Policy warning "non-refundable except regional cancellation" ([T&Cs](https://www.creatorgolfclub.com/terms-and-conditions)). **Funnel**: started → paid → partner invited → partner accepted → handicap verified → approved → grouped | Parity + funnel |
| Golden Ticket | Draw screen: eligibility list (region in UK/IE/EU, paid ≤ cut-off), Run draw (seed shown), assign Regional Final, response deadline, reassign | New |
| Stage progression | "Qualifiers" board: per group Main winner, 2nd Chance winner, Golden Tickets → Regional Final entries. RoW Group Final fixtures. Regional leaderboard → Grand Final field | New |
| Overview | Tournament control room KPIs, round progress, activity feed (as Vercel) | Parity |

### Player (portal)

| RFQ bullet | Screen and behaviour | Parity/New |
|---|---|---|
| Sign up, register team, pay | Sign-up (email + magic link or password) → choose location → team name → **Pay £150 (demo)** → digital ticket | New |
| Invite partner & manage reserves | Your Team: captain, player 2, reserves 1–2, invite by email/link, accept/decline, remove. Under-18 guardian check. Captain's home club | Parity, extended |
| Profile with handicap display & verification | Gender, DOB, pro flag, home club, governing body, WHS ID, index, rounds in last 12 months → tier badge (T1/T2), verification status, **upload screenshot** from MyEG / Golf Ireland / GHIN etc. Tier recalculated live | New |
| Propose, schedule, confirm matches | Next Match: propose ≥3 slots (validator enforces 2 different weekends), course (home team picks), accept/counter/decline. Shows deadline, home/away badge, escalation status, distance between clubs | Parity, extended |
| Live bracket, cohort standings & leaderboard | Main Draw and 2nd Chance bracket views (R64→Final), group standings, Regional/Grand Final leaderboards | Parity, extended |
| Scorecards via BigWedge, then confirmed | "Open in Wedge" (deep link/QR, mocked). Manual scorecard fallback (hole-by-hole gross for each player). Strokes shown per hole. Live match state ("2 UP thru 12"). Submit → opponent Confirm/Dispute | New |
| In-app messaging between opponents | Match chat (4 players + reserves + admins), flag message | Parity |
| Notifications | Bell + list. Email copies visible in the demo outbox | Parity |
| Personal dashboard | Next match card, deadline countdown, team, your matches, route status ("In Main R3" / "2nd Chance R2" / "Qualified – Regional Final, The Grove, 20 Oct") | Parity, extended |

### Public site

The landing page has "Enter your team — £150" (configurable), bracket links per region and group, Regional Final and Grand Final leaderboards, and "Powered by CGC Platform". It is rendered server-side for SEO and caching, as section 5 explains.

### What to mock, and how

| Integration | Production intent (RFQ) | Demo implementation | Interface to keep |
|---|---|---|---|
| Payments | Online entry fees, refunds | `MockGateway`: "Pay (demo)" sets payment Paid with fake ref `demo_pi_xxx`. Refund sets Refunded. Optional "simulate failure" toggle. **No keys, no network** | `PaymentGateway::createCheckout(team, amount)`, `::refund(payment, amount, reason)`, `::handleWebhook()` |
| WHS handicap | "WHS handicap API" | `ManualProvider` (player enters index + rounds, uploads evidence, admin approves) plus `MockLookupProvider` (deterministic index from WHS ID, for a "Check handicap" button) | `HandicapProvider::lookup(governing_body, whs_id): ?HandicapRecord` |
| Email | Notifications and announcements | All mail goes through `Notifier` → `wp_mail`. In demo mode, `pre_wp_mail` short-circuits and writes to `cgc_email_log` with an outbox viewer | `Notifier::send(user, template, data, channels)` |
| BigWedge/Wedge | "Connect via API to receive scores" | **Simulate Wedge result** button builds a realistic hole-by-hole payload, signs it with the demo secret, and POSTs it to the real webhook route (same code path as production). Deep link/QR shown but inert | `ScoreSource` + webhook contract (section 9) |

---

## 5. Architecture: custom tables, one REST API, React portals, Action Scheduler

The architecture follows WordPress's own precedent for high-volume, relational data. WooCommerce moved orders out of posts and postmeta into custom tables, and on a roughly 400,000-order test site that made filtering by customer about **40× faster** and metadata search about 10× faster ([WooCommerce HPOS benchmark](https://developer.woocommerce.com/2023/03/17/performance-benchmarking-for-woocommerce-hpos/)). Post meta's `meta_value` is not indexed, so value searches scan the whole table ([Delicious Brains](https://deliciousbrains.com/?p=176776)). Matches, scores, messages and the audit log for thousands of teams therefore belong in prefixed custom tables created with `dbDelta` inside a versioned upgrade routine ([Learn WordPress](https://learn.wordpress.org/tutorial/custom-database-tables/); [WP VIP](https://docs.wpvip.com/databases/custom-tables/)). None of the existing sports plugins fit. SportsPress is CPT-based and reviewers note few new features for years. Tournamatch has the right report/confirm/dispute UX but is eSports-oriented with single-elimination brackets ([SportsPress](https://wordpress.org/plugins/sportspress/); [Tournamatch readme](https://fastly.jsdelivr.net/wp/plugins/tournamatch/tags/4.4.2/readme.txt)). Borrow their patterns, not their code.

### Plugin skeleton

The project is PHP 8.1+, with `declare(strict_types=1)`, PSR-4 namespace `CGC\Championship\`, and Composer. Action Scheduler is installed via Composer as `woocommerce/action-scheduler` ([Packagist](https://packagist.org/packages/woocommerce/action-scheduler)). The front end is React built with `@wordpress/scripts`. Main file header: `Plugin Name: CGC Championship`, `Version: 0.1.0`, `Requires at least: 6.5`, `Requires PHP: 8.1`, `Text Domain: cgc`. Constants: `CGC_VERSION` (mirrors the header, used for asset cache-busting) and `CGC_DB_VERSION` (changes only when the schema changes). Folders: `src/Domain` (entities and rules engines: HandicapEngine, BracketEngine, MatchStateMachine, QualificationEngine), `src/Infrastructure` (repositories with `$wpdb->prepare`, gateways, providers), `src/Http` (REST controllers), `src/Jobs`, `src/Cli`, `assets/src` (React apps), `templates/emails`, `tests/`.

### Custom tables (all `{$wpdb->prefix}cgc_*`, every table has `id`, `tenant_id`, `created_at`, `updated_at`, `is_demo`)

| Table | Key columns | Key indexes |
|---|---|---|
| `tenants` | name, slug, branding_json, settings_json | slug unique |
| `tournaments` | name, season_year, status, registration_mode (open/invite), fee_mode (team/person), fee_pence, currency, team_size, timezone, golden_ticket_cutoff_at, template_key | (tenant_id, status) |
| `stages` | tournament_id, seq, type (group_ko / regional_final / group_final / grand_final), format, team_size, handicap_profile_json, qualification_rule_json, starts_at, ends_at | (tournament_id, seq) |
| `regions` | tournament_id, name, kind (uk_region / europe / row), regional_final_venue_id, travel_allowance_pence | |
| `locations` | tournament_id, country, city, region_id | |
| `groups` (cohorts) | stage_id, region_id, name, target_size, bracket_size, seeding_method, bye_policy, draw_seed, draw_published_at | (stage_id, region_id) |
| `rounds` | stage_id, group_id null, bracket (main / second_chance / final), round_no, opens_at, closes_at | (stage_id, bracket, round_no) |
| `players` | user_id, display_name, gender, dob, is_pro, guardian_player_id, phone, home_club_id, governing_body, whs_id, handicap_index, index_as_at, rounds_12m, tier, tier_override, esr_pct, verification_status | user_id unique per tenant; whs_id |
| `handicap_verifications` | player_id, claimed_index, claimed_rounds, evidence_attachment_id, provider, status, reviewed_by, reviewed_at, notes | (status) |
| `teams` | tournament_id, name, captain_player_id, location_id, group_id, seed, status (draft/paid/approved/active/eliminated/qualified/withdrawn/dq), route (main / second_chance / golden_ticket / group_final), golden_ticket_id, paid_at | (group_id, status) |
| `team_members` | team_id, player_id, role (captain/player2/reserve), invite_email, invite_token_hash, invite_status, joined_at, left_at | unique (player_id, tournament) while active |
| `courses`, `tees`, `tee_holes` | course name/club/lat/lng; tee name, gender, par, course_rating, slope; hole, par, si, yards | (tee_id, hole) unique |
| `venues` | course_id, event_date, stage_id, region_id, capacity | |
| `matches` | stage_id, group_id, bracket, round_no, slot, code (e.g. `CGC27-NW-G2-M-R3-05`), team_a_id, team_b_id, home_team_id, status, deadline_at, scheduled_at, course_id, played_at, completed_at, winner_team_id, loser_team_id, margin_text, result_method, holes_played, next_match_id, next_slot, loser_next_match_id, loser_next_slot, source (manual/wedge/admin) | **(status, deadline_at)**, (group_id, bracket, round_no) |
| `match_lineups` | match_id, team_id, player_id, snapshot_json (index, tier, tee, CH, PH, strokes, settings hash) | (match_id) |
| `match_proposals` | match_id, team_id, slots_json, course_id, status (open/accepted/countered/declined/expired), responded_at | (match_id) |
| `match_results` | match_id, submitted_by, submitted_at, hole_scores_json, computed_json, winner_team_id, margin_text, status (submitted/confirmed/auto_confirmed/disputed/superseded), confirmed_by, confirmed_at | (match_id, status) |
| `disputes` | match_id, raised_by, reason, status, sla_due_at, resolution, resolved_by | (status, sla_due_at) |
| `payments` | team_id, player_id null, amount_pence, currency, provider ('mock'), provider_ref, status, refunded_pence, refund_reason | (status) |
| `golden_ticket_draws` / `golden_tickets` | rng_seed, eligible_count, drawn_by, drawn_at / draw_id, team_id, venue_id, status (offered/accepted/declined/expired/reassigned), respond_by | |
| `threads`, `thread_participants`, `messages` | context_type (match/team/group), context_id / user_id, last_read_at / body (kses), flagged, flagged_by | (thread_id, created_at) |
| `announcements` | audience_json, subject, body, channels, sent_by, sent_at, recipient_count | |
| `notifications` | user_id, type, payload_json, read_at, emailed_at | **(user_id, read_at)** |
| `email_log` | to, subject, template, body_html, status (logged/sent/failed) | |
| `audit_log` | actor_user_id, action, entity_type, entity_id, before_json, after_json, reason, ip | (entity_type, entity_id) |
| `webhook_events` | source, event_id (unique), signature_valid, payload_json, received_at, processed_at, error | event_id unique |
| `magic_tokens` | user_id, token_hash, expires_at, used_at | token_hash |

Every repository query is scoped by `tenant_id`. For hosting, practitioners recommend separate installs per client over large Multisite networks (GridPane suggests capping Multisite at about 30 sites). That is a vendor's opinion, but it supports keeping both paths open: one install per club, or shared tables plus `tenant_id` ([GridPane](https://gridpane.com/kb/scaling-your-waas-network/)).

### REST API (`cgc/v1`)

Every route uses a `WP_REST_Controller` subclass with a real `permission_callback` that checks capabilities (not just "logged in"), argument schemas with validate/sanitize callbacks, and `WP_Error` returns with HTTP status codes. A route without a `permission_callback` has triggered a notice since WordPress 5.5 ([REST API Handbook](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)). Core routes:

| Resource | Routes (abbreviated) |
|---|---|
| Me / dashboard | `GET /me`, `GET /me/dashboard`, `GET /me/notifications`, `POST /me/notifications/read` |
| Registration | `POST /tournaments/{id}/entries`, `POST /teams/{id}/invites`, `POST /invites/{token}/accept`, `POST /payments/{team}/checkout` (mock), `POST /payments/{id}/refund` |
| Players & handicaps | `GET/PATCH /players/{id}`, `POST /players/{id}/handicap-evidence`, `POST /handicap-verifications/{id}/decision`, `GET /players/{id}/handicap-lookup` (mock) |
| Structure | `CRUD /tournaments`, `/stages`, `/regions`, `/groups`, `POST /groups/build`, `POST /groups/{id}/move-team` |
| Draw | `POST /groups/{id}/draw/preview`, `POST /groups/{id}/draw/swap`, `POST /groups/{id}/draw/publish` |
| Matches | `GET /matches?group=&bracket=&round=&status=`, `GET /matches/{id}`, `POST /matches/{id}/proposals`, `POST /proposals/{id}/respond`, `POST /matches/{id}/lineup`, `GET /matches/{id}/handicaps`, `POST /matches/{id}/results`, `POST /results/{id}/confirm`, `POST /results/{id}/dispute`, `POST /matches/{id}/override` (admin), `POST /matches/{id}/coin-toss` (admin) |
| Brackets/leaderboards | `GET /groups/{id}/bracket?bracket=main|second_chance`, `GET /stages/{id}/leaderboard` (public) |
| Messaging | `GET/POST /threads/{id}/messages`, `POST /messages/{id}/flag` |
| Comms | `POST /announcements`, `GET /announcements/{id}/receipts` |
| Golden Ticket | `POST /tournaments/{id}/golden-tickets/draw`, `POST /golden-tickets/{id}/respond`, `POST /golden-tickets/{id}/reassign` |
| Integrations | `POST /wedge/webhook` (public, signature-verified), `GET /matches/{id}/wedge-setup` (token-auth, for Wedge to pull match config) |
| Demo | `POST /demo/time`, `POST /demo/simulate`, `POST /demo/reset`, `POST /demo/wedge-simulate/{match}` (all `cgc_manage_platform` + demo mode on) |

### Front ends

Build one React app bundle per surface with `@wordpress/scripts`, using `@wordpress/api-fetch`, which attaches the REST nonce, and `@wordpress/components` where useful. Mount by blocks or shortcodes: `[cgc_player_portal]`, `[cgc_admin_portal]` (front-end control room for organisers who shouldn't need wp-admin), and `[cgc_public]` / blocks for bracket and leaderboard. Pass `wp_create_nonce('wp_rest')` and the REST root by inline script. Public bracket and leaderboard pages should be server-rendered and progressively enhanced. The Interactivity API is designed to enhance server-rendered HTML, and its FAQ warns that client-side React on the front end pairs poorly with PHP rendering ([Interactivity API FAQ](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/iapi-faq/)). Keep the Super Admin Demo console and platform settings in wp-admin. Reproduce the Vercel role switcher as a demo-only toolbar that logs in as seeded users (super admin, tournament admin, captain).

### Roles and capabilities

Create roles and capabilities in the versioned upgrade routine, because `add_role()` persists to the database and "sequential calls will do nothing". Always check capabilities, not roles ([Plugin Handbook: Roles](https://developer.wordpress.org/plugins/users/roles-and-capabilities/)).

| Role | Capabilities |
|---|---|
| `cgc_super_admin` (+ `administrator`) | `cgc_manage_platform`, all below |
| `cgc_tournament_admin` | `cgc_manage_tournament`, `cgc_manage_draw`, `cgc_approve_handicaps`, `cgc_resolve_disputes`, `cgc_override_results`, `cgc_send_announcements`, `cgc_manage_payments`, `cgc_moderate_chat`, `cgc_view_audit` |
| `cgc_group_manager` (optional) | above, but object-scoped to assigned regions/groups via `map_meta_cap` |
| `cgc_player` | `cgc_play` (object checks: is member of team; is captain for team admin actions; is participant for match chat/results) |

Magic-link login (for infrequent players) uses `random_bytes(32)`, stores a SHA-256 hash, expires after about 10 minutes, is single-use, is compared with `hash_equals`, and is rate-limited. This is the same model as the Keyless Auth plugin, which also had to fix a timing-attack bug in token comparison ([Keyless Auth readme](https://cdn.jsdelivr.net/wp/plugins/keyless-auth/tags/3.0.2/readme.txt)).

### Background jobs (Action Scheduler, group `cgc`)

Action Scheduler's default runner fires via WP-Cron at most once a minute in batches of 25 with a 30-second limit. Its docs call WP-CLI "the best approach" for throughput ([Action Scheduler perf](https://actionscheduler.org/perf/)). Production should run `wp action-scheduler run` from system cron. The demo can rely on WP-Cron plus the simulator.

| Hook | Trigger | Behaviour (idempotent) |
|---|---|---|
| `cgc_round_open` | round.opens_at | Generate/activate fixtures, notify both teams, set deadline actions |
| `cgc_proposal_escalate` | +48h after a proposal or match activation with no response | Step 1 reminder → step 2 "call/text" nudge with phone shown → step 3 admin queue flag |
| `cgc_round_reminder` | 14d, 72h, 24h before deadline for unplayed matches | Notify captains, admin summary |
| `cgc_match_deadline` | match.deadline_at | If no confirmed result: apply forfeit policy (section 6) or queue for admin |
| `cgc_result_submission_due` | played_at + 48h | If no submission: flag "may forfeit" to admin (Rule 5.10) |
| `cgc_result_autoconfirm` | submitted_at + confirm window (default 48h) | If opponent silent: auto-confirm (Battlefy pattern, **[INFERRED]**) |
| `cgc_advance` | result confirmed / override | Place winner in `next_match`, loser in `loser_next_match` (if first played match), set home team, schedule next actions |
| `cgc_golden_ticket_expire` | respond_by | Mark expired, offer admin reassign |
| `cgc_email_batch` | queued | Send ≤50 emails per action |
| `cgc_sweeper` | every 15 min | Catch any overdue match/result/dispute missed by single actions |

**Clock rule:** every "now" in domain code comes from `CGC\Clock::now()`. That returns the `cgc_demo_now` option when demo mode is on, otherwise real UTC. This makes time travel possible (section 7). Never call `time()` or `current_time()` directly in domain code.

### Wedge webhook endpoint

`POST /wp-json/cgc/v1/wedge/webhook`. Verify `X-CGC-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + raw_body)>` and `X-CGC-Timestamp` within 300 seconds, using `hash_equals`. Read the raw body before parsing. Return a generic 401 on failure. Insert into `webhook_events` with a unique `event_id`, so a duplicate is acknowledged but not reprocessed. Return 200 quickly and enqueue processing. These are standard webhook practices, including Stripe's 300-second tolerance ([InventiveHQ webhook guide](https://inventivehq.com/blog/webhook-signature-verification-guide)).

### Notifications, chat, audit, GDPR, standards

**Notifications:** each event (opponent drawn, proposal received, escalation, reminder, result submitted, dispute, forfeit, advanced, dropped to 2nd Chance, qualified, Golden Ticket) writes one `notifications` row and optionally an email. Templates live in `templates/emails/` and can be overridden by the theme.

**Match chat:** one thread per match (both teams' active members and reserves, plus admins read and moderate). Message bodies sanitised with `wp_kses`, polling every 30 seconds, flagged messages shown in admin moderation (Vercel parity).

**Audit log:** an immutable row for every draw publish, swap, group move, override, handicap decision, refund, coin toss, Golden Ticket draw and DQ, with before/after JSON and a required reason.

**GDPR:** register a personal-data exporter and eraser through `wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers` ([Plugin Handbook: Exporter](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/); [Eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/)). Erasure **anonymises** ("Deleted player #123") rather than deleting results, so historical brackets stay intact. CGC's T&Cs already share profile contact details with opponents for scheduling, so the export should list that sharing ([T&Cs](https://www.creatorgolfclub.com/terms-and-conditions) 2.1).

**Standards:** WordPress Coding Standards via PHPCS, PHPStan with WordPress stubs, PHPUnit for the engines, all SQL through `$wpdb->prepare` (`$wpdb->query()` does no sanitisation ([Learn WordPress](https://learn.wordpress.org/tutorial/custom-database-tables/))), escape late, and prefix every global, option, hook and table with `cgc_`.

---

## 6. Bracket engine: seeding, byes, 2nd Chance construction and the overflow rule

### Seeding order

Standard seeding pairs highest with lowest and arranges the bracket so seeds 1 and 2 can only meet in the final. When byes are needed, the top seeds usually get them ([Wikipedia: Single-elimination](https://en.wikipedia.org/wiki/Single-elimination_tournament); [Wikipedia: Bracket](https://en.wikipedia.org/wiki/Bracket_(tournament))). Implement:

```
seed_order(P):                 # P = power of two
  order = [1]
  while len(order) < P:
      n = 2*len(order)
      order = flatten([s, n+1-s] for s in order)
  return order                 # consecutive pairs = Round 1 matches
# 16 → (1,16)(8,9)(4,13)(5,12)(2,15)(7,10)(3,14)(6,11)
```

Seeding sources (pluggable): `random` (CGC default **[OPEN]**), `combined_index` (as the Vercel demo shows), `manual`, `previous_stage_result`. Golf tools treat these as standard: Golf Genius seeds by index, previous results or custom fields, and DotGolf by acceptance order or prior qualifier ([Golf Genius](https://intercom.help/tournament-management/en/articles/10778552-bracket-tournaments); [Scottish Golf DotGolf](https://scottishgolfltd.zohodesk.eu/portal/en/kb/articles/matchplay-in-the-dotgolf-platform)). Store the random seed used, so a draw can be reproduced and audited.

### Byes

`P = next_pow2(n)`, `byes = P − n`. Generate `seed_order(P)` and treat any seed greater than n as BYE. Byes then fall on seeds 1…b, spread evenly. The bye team is auto-advanced at generation, and BYE-vs-BYE never occurs while n > P/2. The open-source brackets-manager library uses the same semantics: a bye auto-advances the opponent, and re-seeding after creation is best done by regenerating the stage ([brackets-manager.js](https://www.github.com/Drarig29/brackets-manager.js/discussions/130)). **Drafts can be re-run. Published draws can only be changed by swaps (audited) or regeneration of not-yet-played rounds.**

### Main + 2nd Chance construction for a 64-team group

A full 64-team group has 32 Round 1 matches. The 32 losers form 2nd Chance Round 1, which runs alongside Main Round 2. Five 2nd Chance rounds go 32 → 16 → 8 → 4 → 2 → 1, ending on 27 September alongside Main Round 6. That is 63 Main matches plus 31 2nd Chance matches: **94 matches per group**, about 1.5n, against about 2n for full double elimination ([Wikipedia: Double-elimination](https://en.wikipedia.org/wiki/Double-elimination_tournament)). Pre-link the matches. Each Main R1 match `i` has `loser_next_match_id` = 2nd Chance R1 match `ceil(i/2)`, slot `(i odd ? A : B)`. Mirroring the halves this way means teams that met in the same Main R1 pair cannot rematch immediately. Main R2+ losers get `loser_next_match_id = null`, meaning eliminated, unless they are "first played match" losers.

### The bye overflow problem (derived for this brief, verified by script)

With byes, a bye team that loses in Main R2 is a first-played-match loser, but it only becomes known when 2nd Chance R1 closes. It can therefore only enter 2nd Chance R2, which holds 16 teams in a 5-round bracket. For a 64-slot group with `b` byes there are `32 − b` R1 losers. Even if the admin gives some of them 2nd Chance R1 byes to make room, the 5-round 2nd Chance stays valid **only if no more than ⌊b/2⌋ bye teams lose in Main R2**. For example, n=63 (one bye) gives 16 R1-loser survivors plus up to 1 late entrant: 17 teams for a 16-team round. With n=50, the R2 field can reach 23. The engine must therefore implement a group-level `bye_policy`:

| Policy | Behaviour | When to use |
|---|---|---|
| `exact_size` (**default**) | Group builder only publishes power-of-two groups (64/32/16). Remainders are fixed by moving teams between neighbouring groups, waitlist fills, or a smaller group with fewer rounds | Recommended. CGC controls group formation and the date grid assumes 64 |
| `late_entry` | Bye teams that lose in R2 enter 2nd Chance R2 via reserved slots. The engine computes how many 2nd Chance R1 byes to grant. **If overflow occurs, it blocks auto-advance and asks the admin** to (a) add a 2nd Chance play-in inside the R2 window, or (b) award a non-qualifying "guarantee match" | When group sizes can't be equalised |
| `r1_losers_only` | Only Main R1 losers drop. UI warns "two-match guarantee not met for N teams" | Not recommended. Shown for transparency |

Smaller RoW groups need their own round grid. A 32-team group needs 5 Main and 4 2nd Chance rounds, and the engine should derive round counts from `bracket_size` and lay them on the date grid ending 27 September. Whether CGC instead runs every group at 64 is **[OPEN]**.

### Match state machine and auto-advance

```
pending_opponent → open (fixture known, window open)
  → proposing → agreed (date+course) → in_progress/suspended
  → played → result_submitted → confirmed | auto_confirmed | disputed → final
side exits to final: walkover_a | walkover_b | double_forfeit | coin_toss | admin_override
```

The design borrows proven patterns. From Battlefy: the opponent confirms or reports an incorrect score, a report becomes an admin dispute, and silence auto-confirms when the timer ends ([Battlefy](https://help.battlefy.com/en/articles/6966478-using-score-confirmation-on-battlefy)). From start.gg: a red-flag moderation queue where admins report or verify results on players' behalf ([start.gg](https://help.start.gg/en/articles/13766338-online-match-moderation)). From FACEIT: proposal, accept/reject/counter, auto-accept on expiry, and a visible proposal history ([FACEIT](https://support.faceit.com/hc/en-us/articles/19983999962012-Setup-Running-Match-rescheduling-V2)). Flex leagues recommend publishing the unplayed-match policy up front and crediting documented good-faith scheduling effort ([Pickleball.com](https://pickleball.com/docs/en/article/what-is-a-flex-league-in-pickleball.md)).

**Deadline forfeit policy [INFERRED; admin can override]:** at the deadline with no confirmed result, the engine checks the proposal history. If only one team proposed 3 valid dates and responded, it records a walkover to that team. If neither did, it records a double forfeit, and the next-round opponent gets a walkover. If both engaged, the match goes to the admin queue with a "Coin toss" button. If a result was submitted but unconfirmed, it is auto-confirmed.

**Auto-advance** runs on every final result. The winner fills `next_match` slot. A first-played-match loser fills `loser_next_match`. Home team for the next match = whichever team has the earlier `completed_at` on its previous match; if the times are equal, or both teams had byes, it is chosen at random and recorded. Bye completion time = round open time **[OPEN]**. When both slots are filled, the job opens the fixture, notifies both teams and schedules its deadline. CGC's settings toggle "Auto-advance winners" from the Vercel Platform Settings controls whether advancing is immediate or waits for admin approval.

**Manual swap/override:** allowed on unplayed fixtures (swap teams between two matches in the same round), and on results (change winner or method). Every change writes an audit row with before/after JSON. The engine then re-cascades downstream fixtures that haven't been played. If a downstream match has already been played, it is flagged rather than rewritten.

### Stage qualification rules (stored as `qualification_rule_json`)

| Group type | Qualifiers → destination |
|---|---|
| UK / Europe group | Main winner (`route=main`) + 2nd Chance winner (`route=second_chance`, penalty 1 at Regionals) → that region's Regional Final venue |
| Golden Ticket | 4 teams (`route=golden_ticket`) → Organiser-assigned Regional Final. They also keep playing their bracket. Duplicate-qualification rule **[OPEN]**, default "spot not passed down" |
| RoW group | Main winner (home) vs 2nd Chance winner → Group Final (28 Sep–20 Oct) → winner to Grand Final |
| Regional Final | One-round event (default four-ball Stableford, 85% or CGC tier allowance, with penalty). Top N (default 2) → Grand Final. Tie-break cascade: points → countback last 9/6/3/1 → lower combined index → recorded random draw. The countback order is the golf convention and needs CGC confirmation |
| Grand Final | Three configurable rounds (default: R1 four-ball Stableford, R2 four-ball Stableford, R3 matchplay final between top two), penalty removed |

---

## 7. Demo data and time travel: seed a 2027 season and jump to any date

WP-CLI commands load only when `WP_CLI` is defined. Each public method of the command class becomes a subcommand, and output goes through `WP_CLI::success/log/error` ([WP-CLI Commands Cookbook](https://make.wordpress.org/cli/handbook/guides/commands-cookbook/)). Use `fakerphp/faker` (prefixed with Strauss to avoid conflicts) with a fixed `--seed`, so every run produces the same season.

| Command | What it does |
|---|---|
| `wp cgc seed --season=2027 --seed=42 [--scale=demo|full]` | Creates tenant "Creator Golf Club", the 2027 tournament from the CGC template (2026 grid shifted a year), regions and venues (Celtic Manor, Worsley Park, Breadsall Priory, The Grove; Vilamoura courses), about 15 courses/tees with SI, users and teams with realistic UK club names, mixed tiers, about 3% plus handicaps, 2% pros, a few under-18s with guardians, reserves on 40% of teams, paid/pending/refunded payments, pending handicap verifications |
| Default `--scale=demo` groups | SW-1, SW-2, NW-1, NW-2, MID-1, SE-1, SE-2, EUR-1 (Ireland/Belgium/Netherlands): **64 teams each**. RoW: AUS-1 (32), USA-NYC (32), UAE (24, to demonstrate byes and the `late_entry` overflow banner), CAN-TOR (16). About 680 teams, 1,700 players |
| `--scale=full` | About 40 groups of 64 (about 2,500 teams), for performance testing |
| `wp cgc time now` / `wp cgc time set 2027-06-10T12:00` | Read or set `cgc_demo_now` (without running jobs) |
| `wp cgc simulate --to=2027-07-01 [--dispute-rate=0.04 --forfeit-rate=0.03 --late-submit-rate=0.05 --wedge-share=0.7]` | Steps the Clock day by day. On each day it fires due Action Scheduler hooks synchronously in timestamp order, has seeded "bots" make proposals and accept them, plays matches by generating hole-by-hole gross scores from each player's index (normal noise), and sends ~70% of results through the **signed Wedge webhook path** and the rest by manual entry. It then confirms, disputes or misses deadlines according to the rates. Deterministic for a given seed |
| `wp cgc golden-tickets draw [--seed=]` | Runs the draw at the configured cut-off |
| `wp cgc snapshot save <name>` / `restore <name>` | Dumps and restores all `cgc_*` tables plus demo users (fast jumps during the demo with Seb) |
| `wp cgc reset --yes` | Truncates `cgc_*` rows where `is_demo=1`, deletes demo users, unschedules the `cgc` Action Scheduler group, resets the clock |

The **Demo console** in wp-admin exposes the same operations. It has a date slider and presets that call `simulate --to`: *Entries open (15 Jan)*, *Golden Ticket draw (9 Apr)*, *Draw published (26 Apr)*, *Main R1 mid-way (10 May)*, *R1 closed → 2nd Chance populated (25 May)*, *Main R3 mid-way*, *R6 + 2nd Chance R5 closing (27 Sep)*, *RoW Group Finals*, *Regional Finals week*, *Grand Final*. Pre-built snapshots for each preset make jumps instant. Going *backwards* in time restores the nearest earlier snapshot and simulates forward, because replaying history in reverse is not possible. Keep one "hero" team ("Jordan & Riley", from the Vercel data) on a scripted path: wins R1, loses R2 after an R1 bye, drops to 2nd Chance, wins it, gets the Regionals penalty, qualifies for Vilamoura. That gives Seb a single storyline to follow.

---

## 8. Build order for Claude Code, with acceptance tests

**Repository rule: update `HANDOVER.md` at the end of every milestone** (what changed, what is mocked, new shortcuts).

**Repository rule: bump the plugin version on every commit.** Increment the patch number in the main plugin file's `Version:` header and the matching `CGC_VERSION` constant in the same commit (0.1.0 → 0.1.1 …). Bump the minor number at each milestone. Change `CGC_DB_VERSION` only when the schema changes, and use it to trigger `dbDelta` and data migrations. Add a pre-commit check (or CI step) that fails if `Version:` and `CGC_VERSION` differ, or if the header didn't change versus `HEAD`.

| Milestone | Build | Acceptance tests (must pass before moving on) |
|---|---|---|
| **M0 Scaffold** (v0.1) | Plugin header, Composer (PSR-4, Action Scheduler, Faker), `@wordpress/scripts`, activation/upgrade routine, all tables via dbDelta, roles/capabilities, Clock, Settings, audit logger, PHPCS/PHPStan/PHPUnit, version-bump check | Activate on clean WP 6.5+/PHP 8.1 with no notices. All tables exist with indexes. Re-activation is idempotent. Version check blocks a commit without a bump |
| **M1 Handicap engine** (v0.2) | HandicapEngine, course/tee CRUD with validation, profiles, snapshots, Stableford scorer, matchplay scorer (better-ball net, hole result, match state, margin text, dormie/closeout, extra holes) | Worked example returns 0/7/15/20 (CGC), 0/7/15/19 (index setting), 0/8/14/22 and 0/9/15/22 (WHS check cases). Plus +4 → +3 (T1) / +3.6 → +4 rounded (T2). Stroke allocation for 20 = 2 on SI 1–2. Stableford table correct. Margin "3&2" when 3 up with 2 to play |
| **M2 Registration & teams** (v0.3) | Sign-up, magic link, location choice, team create, mock payment, partner/reserve invites, eligibility (age 16+, under-18 guardian, pro-with-amateur, one team per player), handicap evidence upload + admin queue, registration funnel | Under-18 without guardian blocked. Second team membership blocked. Payment Paid (demo) visible in admin. Funnel counts match seeded data. Refund (mock) writes audit + changes status |
| **M3 Structure & draw** (v0.4) | Regions, locations, venues, group builder (by location/distance, power-of-two targets), BracketEngine (seed order, byes, pre-linked Main + 2nd Chance), preview/swap/publish, random home team R1 | 64-team draw yields 32+16+8+4+2+1 Main and 16+8+4+2+1 2nd Chance fixtures (94). Seeds 1 and 2 in opposite halves. Byes on seeds 1..b. Swap writes audit. Re-run disabled after publish |
| **M4 Match lifecycle** (v0.5) | Proposals (≥3 dates, 2 weekends rule), accept/counter, 48h escalation, reminders, deadlines, coin toss, late-cancellation flag, suspended matches, match chat, lineup selection (captain or P2 must play) | Proposal with 3 dates on one weekend rejected. Silence triggers escalation at +48h by Clock. Deadline triggers the forfeit policy correctly in all 3 cases. Lineup without captain/P2 rejected |
| **M5 Scoring & confirmation** (v0.6) | Manual scorecard, result submission, opponent confirm/dispute, 48h submission check, auto-confirm, dispute queue with SLA, putt-off/sudden-death result entry, Wedge webhook route + signature + idempotency + simulator button | Valid signed payload creates result. Same `event_id` twice gives one result. Bad signature or timestamp older than 300s → 401. Dispute appears in queue with SLA. Putt-off result stored as method `putt_off` with detail |
| **M6 Progression** (v0.7) | Auto-advance, first-played-match drop, `bye_policy` incl. overflow detection, home/away by completion time, overrides with re-cascade, DQ cascade | Bye team losing R2 lands in 2nd Chance R2 slot (`late_entry`). Overflow case (UAE 24) raises admin decision and blocks auto-advance. Earlier finisher is home next round. Override re-cascades only unplayed downstream fixtures |
| **M7 Finals & Golden Ticket** (v0.8) | Qualification engine, Regional Final events (Stableford leaderboard, penalty), RoW Group Final (Main team home), Grand Final multi-round leaderboard (penalty removed), Golden Ticket draw/respond/expire/reassign | Each UK/EU group sends exactly 2 teams to its region's venue. 2nd Chance team's PH is one lower at Regionals and back to normal at the Grand Final. Golden Ticket draw only picks eligible paid-by-cut-off UK/IE/EU teams and is reproducible from its seed |
| **M8 Comms** (v0.9) | Notifications, announcements by audience, email templates, outbox log, read receipts, chat moderation | Announcement to "SW groups, unplayed <72h" reaches only matching captains. Every notification type has a template. Demo mode sends no real email |
| **M9 Portals UI** (v0.10) | Player, Tournament Admin, Super Admin and Public screens at Vercel parity plus the New screens in section 4, role-switch toolbar, mobile-first player views | Each Vercel screen has a working counterpart. Lighthouse accessibility ≥ 90 on player dashboard. All lists paginate at full scale |
| **M10 Demo & hardening** (v1.0-demo) | WP-CLI seed/simulate/time/snapshot/reset, Demo console, hero storyline, GDPR exporter/eraser, performance pass at `--scale=full` | `seed` + `simulate --to=<Grand Final>` completes without errors, with every group producing qualifiers. Same seed gives identical results. Reset leaves no demo rows. Exporter returns a player's data. Eraser anonymises while brackets still render. A scripted 20-minute walkthrough for Seb (section 1 table) passes end to end. `HANDOVER.md` is complete and a developer new to the repo can install, seed and run the demo from it alone |

### Build timeline: demo to Seb on 11 December 2026

The build started on Thursday 26 November 2026, so the plan is squeezed into two weeks. Each milestone is a card in Forge (CGC Demo Platform), assigned to the Agent with Luke reviewing. Screens are built alongside each engine, so something new is visible every few days.

| Due | Milestones | What's built | Checkpoint |
|---|---|---|---|
| Fri 27 Nov | M0, M1 | Plugin set-up, database, roles, handicap engine and scoring maths | Worked handicap example gives the right strokes |
| Mon 30 Nov | M2 | Sign-up, teams, partner and reserve invites, mock payment, handicap approval queue | A team can enter and pay (demo) |
| Tue 1 Dec | M3 | Regions, groups, draw engine, Main + 2nd Chance brackets | A 64-team draw shows on screen |
| Wed 2 Dec | M4 | Proposing dates, accept/counter, 48h reminders, deadlines, coin toss, match chat | A match can be arranged end to end |
| Thu 3 Dec | M5 | Scorecards, confirm/dispute, simulated Wedge results | A result goes in and the opponent confirms it. Seb's answers due 3 Dec |
| Fri 4 Dec | M6 | Winners advance, losers drop to 2nd Chance, home/away, overrides | Brackets fill themselves as results land |
| Mon 7 Dec | M7 | Regional Finals, RoW Group Final, Grand Final, Golden Ticket draw | A full season simulates from entry to Vilamoura |
| Tue 8 Dec | M8, M9 | Notifications and announcements; finish and polish all screens to Vercel standard | Every Vercel screen works with live data |
| Wed 9 Dec | M10 | Demo console, time travel, saved snapshots, "hero" team storyline, handover guide | One-click jump to any point in the season |
| Thu 10 Dec | Rehearsal | Full 20-minute walkthrough, fix anything that breaks | Dress rehearsal passes |
| Fri 11 Dec | Demo | Demo to Seb | |

**To hit the date:**

- **Seb's answers to the section 10 questions are needed by 3 December** (before M6–M7). Without them, the demo runs on the stated defaults.
- **If the build slips, drop these first:** the 2,500-team performance test, the GDPR export/erase tools, the Super Admin billing screen, and magic-link login (use passwords). None of them is needed for Seb's walkthrough.
- **Never drop:** the handicap engine, the brackets (including 2nd Chance), time travel and the hero storyline.

---

## 9. Production phase: what the dev team must add

**BigWedge / Wedge.** The scoring app is "Wedge – The Smarter Way to Play Golf" by Big Wedge Ltd. It is a free iOS app with Stroke Play, Match Play and Stableford modes, real-time head-to-head match scoring, QR-joined shared rounds, and course data including stroke index for "tens of thousands of courses" ([App Store](https://apps.apple.com/app/id6748689243)). Its terms describe a scorekeeping app and say nothing about an API, developers or third-party integrations ([Wedge Terms](https://app.bigwedgegolf.com/terms)). CGC's own rules still refer to an "official digital scorecard" in the CGC portal ([Tournament Rules](https://www.creatorgolfclub.com/tournament-rules) 2.8), and the listing does not confirm pairs better-ball matchplay or custom allowances, so Wedge may need those modes added. **Big Wedge owns CGC**, so this is an internal integration: no commercial agreement, no restrictions on sharing data or code between the two, and the production dev team should work directly with the Wedge developers. That opens options beyond a basic score feed, to agree jointly: one player account across Wedge and the CGC platform (single sign-on), Wedge showing CGC fixtures and strokes, the platform reusing Wedge's course database (rating, slope, stroke index), and live match state in the CGC bracket. The proposed contract, which the demo already implements, has two parts. First, the plugin issues a match code and a deep link/QR (`wedge://cgc/match/{code}?t=<token>`, or a universal link), and Wedge pulls `GET /cgc/v1/matches/{code}/wedge-setup`: format, four players, CGC strokes per player per hole, course and tee. Second, Wedge pushes signed events (`round.started`, `hole.scored`, `match.completed`, `match.result_confirmed`) to the webhook. Example `match.completed` payload:

```json
{
  "event": "match.completed",
  "event_id": "evt_01JCGC8Q3",
  "sent_at": "2027-06-14T16:42:10Z",
  "cgc_match_id": "CGC27-NW-G2-M-R2-14",
  "wedge_round_id": "rnd_8f3a",
  "format": "fourball_matchplay",
  "course": {"name": "Northwood Heath GC", "wedge_course_id": "c_1182", "tee": "Yellow", "par": 72},
  "teams": [
    {"cgc_team_id": 123, "players": [{"cgc_player_id": 11, "wedge_user_id": "u_91", "strokes": 0}, {"cgc_player_id": 12, "wedge_user_id": "u_92", "strokes": 7}]},
    {"cgc_team_id": 456, "players": [{"cgc_player_id": 21, "wedge_user_id": "u_93", "strokes": 15}, {"cgc_player_id": 22, "wedge_user_id": "u_94", "strokes": 20}]}
  ],
  "holes": [
    {"hole": 1, "par": 4, "si": 7, "gross": {"11": 5, "12": 4, "21": 4, "22": 6}, "net_best": {"123": 3, "456": 3}, "hole_winner": null, "match_state": "AS"}
  ],
  "result": {"winner_team_id": 123, "margin": "3&2", "holes_played": 16, "extra_holes": 0, "tiebreak": null},
  "signed_off_by": [{"cgc_player_id": 11, "at": "2027-06-14T16:40:02Z"}, {"cgc_player_id": 21, "at": "2027-06-14T16:41:30Z"}]
}
```

The plugin **recomputes** the result from gross scores using its own snapshot of strokes and flags any mismatch with Wedge's margin as a dispute. It auto-confirms only if both captains signed off in Wedge. Items to settle with the Wedge team are identity linking (shared accounts or a one-time code), Android parity, better-ball matchplay with CGC's allowances, and who builds which side. Player data rules (UK GDPR) still apply inside the group, so the CGC entry terms and Wedge's privacy policy should say that round data is shared between them.

**Handicaps (WHS).** No public self-serve WHS lookup API exists in GB&I. England, Scotland, Wales and Ireland run on the DotGolf platform. Access to the Central Database of Handicaps is granted to licensed vendors, for example Golf Genius, an "authorised WHS licensee" whose HandicapMaster integration matches golfers by national ID ([Golf Australia: DotGolf](https://help.golf.com.au/support/solutions/articles/51000417573-who-are-dotgolf-); [Golf Business News](https://golfbusinessnews.com/news/corporate/golf-genius-approved-to-deliver-whs-for-home-nations/); [Golf Genius help](https://intercom.help/tournament-management/en/articles/10777795-handicapmaster-integration)). In the US, the USGA's Golfer Product Access programme gives index and scoring-record access for **$6,000 a year**, under a signed agreement, for products serving US customers ([USGA GPA form](https://form.jotform.com/222006675143146)). Australia accredits "Tier 3" providers ([Golf Australia archive](https://archive.golf.org.au/2021/02/tier-3-companies/)). England Golf's iGolf gives non-club golfers an official index, and CGC already bundles a 30-day iGolf trial ([Golf Business News](https://golfbusinessnews.com/news/growing-the-game/england-golf-unveils-igolf-platform-to-give-non-club-golfers-an-official-handicap/); [Entry details](https://www.creatorgolfclub.com/entry-details)). For launch, keep manual evidence plus admin approval (the demo flow) and pursue a phase-2 partnership: approach England Golf / Golf Ireland about read access, or partner with a licensed vendor. GHIN is only worth $6,000 if US entries grow. The RFQ's "WHS handicap API" line should be presented to CGC as a partnership item, not an off-the-shelf integration.

**Payments, email, hosting.** Swap `MockGateway` for Stripe (Checkout + webhooks + Refunds), keeping the `payments` table and refund audit. Swap the email log for a transactional provider with SPF/DKIM/DMARC (the Vercel settings already name Postmark). Run Action Scheduler from system cron via WP-CLI ([Action Scheduler perf](https://actionscheduler.org/perf/)). Add an object cache. Load-test at about 2,500 teams.

**Current platform.** SPORTINGLY LTD is a real, active UK company, incorporated in 2023 and renamed from a recruitment shell ([Companies House](https://find-and-update.company-information.service.gov.uk/company/14647319)). No public product documentation or app listing links it to CGC. CGC should obtain a full data export (players, teams, handicap evidence, 2026 results) and confirm data ownership before migration.

---

## 10. Open questions CGC must answer before the dev team quotes

| # | Question | Demo default until answered |
|---|---|---|
| 1 | Do only losers of a team's **first played** match enter 2nd Chance, with later Main losers eliminated? | Yes |
| 2 | Are all groups exactly 64 teams? If not, which bye policy applies (exact size / late entry / R1 losers only)? | `exact_size`; UAE group shows `late_entry` |
| 3 | How is the draw seeded: random, by handicap, manual? | Random, with stored seed |
| 4 | Coin toss on deadlock: decides the **date** (FAQ) or the **winner** (Rule 5.7)? Run in-app by admin? | Winner, in-app, recorded |
| 5 | Tier allowance on **course handicap** (Rule 6.3) or **index** (FAQ)? Pct-then-difference (current CGC) or WHS 2024 difference-then-pct? Rounding of .5? | CH, pct-then-diff, half up |
| 6 | Second Chance "one-shot penalty": one fewer stroke per player, or one per team? | Per player |
| 7 | Pros "play off 0": playing handicap 0, or index 0 (course handicap then = CR − Par)? | PH 0 |
| 8 | Fewer than 3 qualifying rounds: ineligible, or allowed at a Tier 2 floor? How does a 28+ player "reduce" to 28 (self-declared)? | Ineligible until verified; cap applied automatically |
| 9 | Home status after a bye, walkover or forfeit: which timestamp counts? | Bye = round open; forfeit = ruling time |
| 10 | Result window: opponent confirmation timer and auto-confirm, or admin approval of every result? | 48h then auto-confirm |
| 11 | Regional Final format, allowance and number of Grand Final qualifiers per region; Grand Final's three formats | Four-ball Stableford, top 2; 3 rounds as section 6 |
| 12 | Which Regional Final do Ireland, Belgium and Netherlands teams attend? How are Scotland, Wales, N. Ireland and North-East England mapped to regions? | EUR-1 → The Grove; nearest by postcode |
| 13 | Golden Ticket winner who also qualifies on the course: does the spot pass down? Response window length? | Not passed down; 7 days |
| 14 | Roster changes after qualifying; reserve approval; can a captain be replaced? | Allowed until Regional Final entry list locks; admin approval |
| 15 | Is scoring in Wedge mandatory, or can the CGC portal scorecard remain? Which Wedge developers will build their side of the link, and on what timeline? Shared player accounts across Wedge and CGC? | Both supported |
| 16 | 2027 dates, fee (£150/team or £75/person), entry cap, invite-only events (e.g. creator invitationals)? | 2026 grid +1 year, £150 |
| 17 | Disputes: SLA target, evidence required, and appeal route (Handicap Policy has a 48h written appeal to an independent panel)? | 24h SLA (Vercel), 48h appeal |
| 18 | Hosting model for future SaaS: one install per organiser or shared multi-tenant? | `tenant_id` everywhere; single install |

---

## Conclusion

The hard part of this demo is not the screens, which the Vercel prototype has largely designed. It is three rule interactions that a click-through can hide and a working system cannot. CGC's handicap method sits deliberately apart from current WHS four-ball practice. The "guaranteed two matches" promise quietly depends on group sizes being powers of two. And three different routes into the finals all have to be tracked per team. If Claude Code builds the engines first (handicap, bracket, state machine, clock), with every disputed rule as a setting and every override audited, the demo to Seb becomes a replay of a real season rather than a slideshow. The dev team's quote then becomes a hardening exercise against a working specification, which is exactly what the RFQ asks for.

The second implication is commercial. The two integrations the RFQ treats as plumbing are very different. Wedge has no public API, but Big Wedge owns CGC, so that link is an internal build the two teams can design together. GB&I has no open WHS API, so handicap lookups are a longer-term licensing conversation with outside bodies. Presenting both as defined interfaces with working mocks lets CGC launch in January 2027 on manual handicap verification, with simulated-then-real Wedge results, without waiting on third parties.
