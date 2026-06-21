# iPop360 History

2026-06-19 — Kill Dead Yelp Weights: Set Yelp ranking weights to 0 after Yelp removal, all tests pass
2026-06-20 — Parallel Source Fetch: Implemented concurrent fetching for BizData/Foursquare/Overpass sources, reducing live-search latency from sum to slowest source only
2026-06-20 — Scoring Schedule & Cache GC: Chunked ScoreRestaurants (500/chunk, transactional batch updates), added apicache:gc command with --dry-run, scheduled daily scoring (02:00 UTC) and nightly GC (03:00 UTC), expanded enrichment to all configured cities
2026-06-20 — Docs Reconciliation: Updated ranking-metrics.md (weight table, free-source landscape, data_completeness fields), marked Proximity DONE in ranking-improvements.md, updated README.md Key Services
- Spec 012: SerpApi Source — Wired SerpApiService into parallel fetch pools (LiveSearchService, RestaurantEnrichmentService); service already existed with key gating and normalization
2026-06-21 — Spec 017: SerpApi Quality Signal + Weight Recalibration — Enabled the validated free SerpApi key, recalibrated weights so rating+reviews lead (0.30/0.25) and proximity is a tiebreaker (0.15), fixed 3 dormant bugs (SerpApi invalid ll zoom, compact() radius, quality-gate keyed on wrong API), added `search:audit` command; live search now ranks real rated restaurants (e.g. Gus's World Famous Fried Chicken) instead of nearest OSM nodes
