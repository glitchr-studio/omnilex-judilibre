# omnilex/judilibre

Judilibre for [glitchr/omnilex](https://github.com/glitchr-studio/omnilex): the decisions of
the Cour de cassation and of the judicial courts, published as open data by the Cour de
cassation through PISTE - a full-text search with filters, one decision whole, the texts it
applies and the case law it is brought together with, what changed since a date.

```php
$judilibre = (new JudilibreSourceFactory($http))->create(['client_id' => '...', 'client_secret' => '...']);

$results = $judilibre->search(new Query(text: 'expropriation', jurisdictions: ['cc'], types: ['arret'], from: '2018-01-01'));
$decision = $judilibre->decision($results->items[0]->id);
$decision->ecli();                    // ECLI:FR:CCASS:2018:C301117
$decision->number;                    // 17-18.194
$decision->content;                   // the full text, pseudonymised
$decision->citations;                 // texts applied, decision contested, case law brought together

$judilibre->search(new Query(number: '17-18.194'));                              // by numéro de pourvoi
$judilibre->recent(new DateTimeImmutable('-7 days'), new Query(jurisdictions: ['cc']));
```

```yaml
omnilex:
    sources:
        judilibre: { factory: judilibre, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
```

## Pseudonymised decisions: never re-identified

The decisions Judilibre publishes are pseudonymised by the Cour de cassation before
publication (the API describes a decision's `text` as "texte intégral et pseudonymisé"). This
package gives the text **as published** (`Decision::$pseudonymised` is `true`) and **never
tries to re-identify a person**: it does not cross a decision with another source to recover a
name, keeps no table of parties, and its models have nowhere to put one. An application built
on it must not either.

Two more rules follow from the API's own description:

- a decision flagged `to_be_deleted` (`$decision->raw['to_be_deleted']`), or listed as deleted
  by `changes()`, "doit être supprimée des archives ou des bases de données du réutilisateur":
  an application that keeps copies must drop it;
- the re-use is subject to the Cour de cassation's
  [conditions for the re-use of the data of court decisions](https://www.courdecassation.fr/conditions-generales-dutilisation-pour-la-reutilisation-des-donnees-issues-des-decisions-de-justice):
  read them before publishing anything built on these decisions.

## Not verified against the real service

This package was written from the API's public OpenAPI description, without PISTE credentials:
**no call was made to the real API**. The tests run on the examples that description gives.
`.samples/live.php` of the workspace runs a real search and reads a real decision as soon as
`PISTE_CLIENT_ID` and `PISTE_CLIENT_SECRET` are set. See
[what is not verified](docs/index.md#not-verified).

## What you need to obtain

The API is free, behind [PISTE](https://piste.gouv.fr), the State's API portal.

1. Create an account: <https://piste.gouv.fr/registration>, and activate it.
2. Accept the terms of use of the **JUDILIBRE** API ("API" > "Consentement CGU API"; search
   for "Judilibre"), for the sandbox and for production.
3. In "Applications", open the application (a sandbox one, `APP_SANDBOX_...`, is created with
   the account; create one for production) and tick the **JUDILIBRE** API in its list of APIs.
4. Copy the application's OAuth credentials (**Client ID**, **Client Secret**) - or its **API
   key**, sent as the `KeyId` header.

| Option | |
|---|---|
| `client_id`, `client_secret` | the application's OAuth credentials |
| `key_id` | or the application's API key, alone |
| `sandbox` | `true` with the sandbox application's credentials |
| `recent` | the date `recent()` reads: `update` (created or updated in the base, default) or `creation` |
| `resolve_references` | `true`: labels in place of keys in the answers (`Cour de cassation` for `cc`) |
| `throttle` | seconds between two calls (0.5) |

The data are under the [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence/);
their use is subject to the terms of PISTE, to the re-use conditions above, and to quotas per
application (a quota spent comes back as a `RateLimitedException`).

See [docs/](docs/index.md).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
