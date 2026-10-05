# EXPLAIN fixtures

Each `<name>.json` holds what the analyzer saw for `tests/sql/<name>.sql` on the schema in `tests/sql/schema.sql`: the interpolated `sql`, `explain` (`EXPLAIN FORMAT=JSON`), `explain_analyze` (`null` unless the statement is a read-only SELECT), `warnings` (`SHOW WARNINGS`), `schema` (information_schema per table) and `optimizer_trace` (the `OptimizerTrace::excerpt()` of `information_schema.OPTIMIZER_TRACE`; absent in fixtures recorded before it was captured, `null` when the server gave none). It is the JSON form of `QueryContext::toArray()`; `Fixture::load('<name>.sql')` turns it back into a `QueryContext`.

`expected.php` lists the detector types each fixture must produce, deduplicated and sorted. `DetectorCorpusTest` runs every fixture through `ExplainAnalyzer` and compares.

Re-record with `php tests/fixtures/record.php [<name>.sql ...]` against a MySQL loaded with `tests/sql/schema.sql` (connection from `SQL_QUALITY_DSN` / `SQL_QUALITY_USER` / `SQL_QUALITY_PASSWORD`, defaulting to root at 127.0.0.1, database `test`). No argument records every file in `tests/sql/`. `explain_analyze` and the `status` timestamps in `schema` change with every recording; `explain`, `warnings` and the rest of `schema` should not, though InnoDB statistics can shift row estimates and costs by a few units in both `explain` and `optimizer_trace`.

Files that cannot be explained have no fixture: `13_invalid.sql` (not SELECT/DML), `16_listed_num_parameters.sql` (no parameters recorded), `17_empty_list.sql` (empty list parameter).
