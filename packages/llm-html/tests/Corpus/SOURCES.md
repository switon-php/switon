# Corpus sources (mined goals)

We do **not** vendor upstream HTML fixtures (licensing + size). We mine **scenario goals** and rewrite as synthetic HTML.

| Source | What we take | What we skip |
|--------|--------------|--------------|
| [kepano/defuddle](https://github.com/kepano/defuddle) `tests/fixtures` (209) | **All fixture names** swept in `CorpusMinedWave5` (`mined.defuddle.fx.*`) + earlier hand goals | Site extractors, mathjax deep fidelity, full expected.md goldens |
| [adbar/trafilatura](https://github.com/adbar/trafilatura) `tests/evaldata.json` | `with` / `without` roles + bulk chrome phrases | Full archived pages, HTTP |
| [mozilla/readability](https://github.com/mozilla/readability) `test/test-pages` | Nearly all page names as synthetic goals | Copyrighted `source.html`, empty-on-failure semantics |

Files:
- `CorpusCatalog.php` — base hand-authored families
- `CorpusMinedCatalog.php` — waves 1–3
- `CorpusMinedWave4.php` — wave4 + `edge.*`
- `CorpusMinedWave5.php` — full defuddle name sweep + bulk trafilatura + agent edges
- `defuddle-fixture-names.php` — name list only

Product filter: LLM-oriented extraction (soft fallback, chrome out, structure in).
