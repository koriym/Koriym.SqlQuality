# EXPLAIN fixtures

Each `<name>.json` holds what the analyzer saw for `tests/sql/<name>.sql` on the schema in `tests/sql/schema.sql`: the interpolated `sql`, `explain` (`EXPLAIN FORMAT=JSON`), `explain_analyze` (`null` unless the statement is a read-only SELECT), `warnings` (`SHOW WARNINGS`) and `schema` (information_schema per table).

`expected.php` lists the detector types each fixture must produce, deduplicated and sorted. `DetectorCorpusTest` runs every fixture through `ExplainAnalyzer` and compares.

Files that cannot be explained have no fixture: `13_invalid.sql` (not SELECT/DML), `16_listed_num_parameters.sql` (no parameters recorded), `17_empty_list.sql` (empty list parameter).
