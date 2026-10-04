# omnilex/judilibre

## Installation

```sh
composer require omnilex/judilibre
```

```php
use Omnilex\Judilibre\JudilibreSourceFactory;

$judilibre = (new JudilibreSourceFactory($httpClient, $tokenStore))->create([
    'client_id' => $_ENV['PISTE_CLIENT_ID'],
    'client_secret' => $_ENV['PISTE_CLIENT_SECRET'],
]);
```

## The documentation read

Read on 2026-10-04:

- the API's OpenAPI description, `JUDILIBRE-public.json` (OpenAPI 3.0.2, version 1.2.5), in the
  Cour de cassation's repository:
  <https://github.com/cour-de-cassation/judilibre-search/blob/master/public/JUDILIBRE-public.json>;
- the repository's README (first steps on PISTE):
  <https://github.com/cour-de-cassation/judilibre-search>;
- data.gouv.fr's record of the service: <https://www.data.gouv.fr/dataservices/api-judilibre/>.

Base URLs: `https://api.piste.gouv.fr/cassation/judilibre/v1.0` (production),
`https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0` (sandbox).

## Calls

| Method | API |
|---|---|
| `search()` with a `text` or a `number` | `GET /search`: `query`, `operator` (`and`; `exact` for an expression in double quotes and for a number), `jurisdiction`, `location`, `type`, `theme`, `date_start`, `date_end`, `sort`, `order`, `page_size` (50 at most), `page` |
| `search()` without a text | `GET /export`: the same filters, by decision date (`/search` answers nothing to an empty query) |
| `decision()` | `GET /decision?id=` |
| `citations()` | from `GET /decision`: `visa` (texts applied), `contested` (the decision under appeal), `forward` (the decision that followed), `rapprochements` (case law brought together) |
| `recent()` | `GET /export`: `date_start`, `date_type` (`update` or `creation`), `order=desc`, `batch_size` (1000 at most), `batch`, `abridged=true` |
| `taxonomy($id, $context)` | `GET /taxonomy`: the keys the filters take, with their labels |
| `changes($since, $cursor)` | `GET /transactionalhistory`: each decision created, updated or deleted since a moment |

### Query

| | |
|---|---|
| `text` | every word (`operator=and`); `"..."` for the exact expression |
| `number` | a case number, searched exactly; a pourvoi is normalised (`n° C 17-18.194` → `17-18.194`) |
| `jurisdictions` | a kind of court (`cc`, `ca`, `tj`, `tcom`, `cph`) → `jurisdiction`; anything else is a seat (`ca_paris`, `tj33063`) → `location` |
| `types` | `arret`, `qpc`, `ordonnance`, `saisie`... (`taxonomy('type')`) |
| `subjects` | the Cour de cassation's themes (`taxonomy('theme')`) |
| `from`, `to` | the decision's date |
| `sort` | `relevance` → `scorepub` (relevance and publication level); `newest`, `oldest` → `date` |
| `limit`, `cursor` | 50 a page at most, 10 000 results at most; the cursor is the page number |

`title` and `at` are refused: Judilibre has neither.

### Reading a decision

Judilibre names a decision by its own identifier (24 hexadecimal characters): the `id` of a
search result. An ECLI or a numéro de pourvoi is searched first:

```php
$found = $judilibre->search(new Query(number: '17-18.194'));
$decision = $judilibre->decision($found->items[0]->id);
```

A `Decision` holds the ECLI and every numéro de pourvoi (`identifiers`), the court with its
chamber and formation (their keys; their labels with `resolve_references` or `taxonomy()`), the
date, the nature, the solution, the publication level, the summary, the themes, the full text,
and in `raw` what the models do not carry: the `zones` of the text (introduction, exposé du
litige, moyens, motivations, dispositif, annexes: character offsets), `nac`, `files`,
`timeline`, `titlesAndSummaries`.

### Keeping a copy up to date

```php
$changes = $judilibre->changes($lastSync);
foreach ($changes['transactions'] as $transaction) {
    match ($transaction['action']) {
        'deleted' => $store->delete($transaction['id']),          // withdrawn: the copy must go
        default => $store->save($judilibre->decision($transaction['id'])),
    };
}
// $changes['next']: the next page, a query string valid for a minute
```

## What is not supported, and why

| | Why |
|---|---|
| `decision()` by ECLI or by numéro de pourvoi | `GET /decision` takes Judilibre's `id` only; search by `number` first |
| `title`, `at` | no such criterion in `GET /search` |
| A text along with a number | `/search` has one `query` |
| Texts, articles | Judilibre holds decisions; the texts a decision applies are links (`visa`) to Légifrance |
| `GET /scan`, `GET /stats`, `GET /healthcheck` | documented; not mapped (use the HTTP client) |
| Re-identification of the persons in a decision | never: the decisions are given as published, pseudonymised (see the README) |

<a id="not-verified"></a>
## Not verified

No PISTE credentials were available: nothing was checked against the real API. In particular:

- the OpenAPI description declares the `KeyId` header as the API's security scheme; PISTE's
  gateway answers an unsigned call with `WWW-Authenticate: Bearer` (seen on 2026-10-04). Both
  ways of signing are offered (`client_id` + `client_secret`, or `key_id`); neither was tried
  with real credentials;
- the action names of `/transactionalhistory` other than `created` (the example's);
- `visa`, `contested`, `forward` and `rapprochements` are read after their documented schemas
  (`textLink`, `decisionLink`): the description's example decision has none;
- with `resolve_references`, the API puts labels where the keys were: `Court::$code` is then null;
- the quotas "will be on the PISTE portal": not public. The description limits `/export` to 10
  per batch and 1 000 in all for an unauthenticated connection, 1 000 per batch otherwise.

## Tests

`Tests/JudilibreSourceTest.php`, on `MockHttpClient`, with the examples of the OpenAPI
description as fixtures (a search for "expropriation", the decision 17-18.194 of 20 December
2018, an export batch, the publication taxonomy, the transactional history).
`decision-links.json` is that decision with links composed after the documented schemas.
